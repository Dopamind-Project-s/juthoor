<?php

use App\Models\Assertion;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonMerge;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\AuditReversionService;
use App\Services\Genealogy\PersonMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Root → published parent → published child; users per role, a second admin and a super admin.
 *
 * @return array<string, mixed>
 */
function recoveryFamily(string $slug = 'recovery'): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة '.$slug, 'slug' => $slug]);
    $tree = $family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $person = fn (string $name, array $extra = []) => $tree->people()->create($extra + ['first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $root = $person('الجد');
    $tree->update(['root_person_id' => $root->id]);
    $parent = $person('سليمان', ['birth_year' => 1750, 'field_visibility' => ['birth_year' => 'public']]);
    $tree->personRelationships()->create(['person_id' => $parent->id, 'related_person_id' => $root->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $child = $person('قاسم');
    $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $parent->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $users = [];
    foreach (['reader', 'editor', 'admin', 'admin2'] as $role) {
        $users[$role] = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $users[$role]->id, 'role' => $role === 'admin2' ? 'admin' : $role]);
    }
    $users['super'] = User::factory()->create()->forceFill(['is_super_admin' => true]);
    $users['super']->save();

    return ['family' => $family, 'tree' => $tree->fresh(), 'root' => $root, 'parent' => $parent, 'child' => $child, ...$users];
}

/** @param array<string, mixed> $overrides */
function updateParent($test, array $fixture, array $overrides, string $role = 'admin')
{
    return $test->actingAs($fixture[$role])->put(route('management.people.update', [$fixture['family']->slug, $fixture['parent']->id]), $overrides + [
        'first_name_ar' => 'سليمان', 'full_name_ar' => 'سليمان', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '0', 'birth_year' => 1750,
    ]);
}

function latestLog(string $type, int $id, string $action): AuditLog
{
    return AuditLog::query()->where('auditable_type', $type)->where('auditable_id', $id)->where('action', $action)->latest('id')->firstOrFail();
}

function recover($test, array $fixture, AuditLog $log, string $role = 'admin', array $extra = [])
{
    $fingerprint = app(AuditReversionService::class)->preview($log)['fingerprint'];

    return $test->actingAs($fixture[$role])->post(route('management.audit.revert.store', $log->id), $extra + ['reason' => 'استرجاع مبرر', 'confirm' => 1, 'fingerprint' => $fingerprint]);
}

test('an eligible field change is previewed read-only, reverted once and recorded as a linked operation', function () {
    $fixture = recoveryFamily();
    updateParent($this, $fixture, ['title' => 'لقب خاطئ'])->assertSessionHasNoErrors();
    $log = latestLog(Person::class, $fixture['parent']->id, 'updated');

    $this->actingAs($fixture['admin'])->get(route('management.audit.show', $log->id))->assertSee('قابل لـاسترجاع التغيير');
    $this->get(route('management.audit.revert.preview', $log->id))->assertOk()->assertSee('لقب خاطئ')->assertSee('تأكيد الاسترجاع');
    expect($fixture['parent']->fresh()->title)->toBe('لقب خاطئ');

    recover($this, $fixture, $log)->assertSessionHasNoErrors();
    $recovery = AuditLog::query()->where('reverted_from_id', $log->id)->sole();
    expect($fixture['parent']->fresh()->title)->toBeNull()->and($recovery->action)->toBe('reverted')->and($recovery->reason)->toBe('استرجاع مبرر');
    $this->post(route('management.audit.revert.store', $log->id), ['reason' => 'مرة ثانية', 'confirm' => 1])->assertSessionHasErrors('change');
    $this->get(route('management.recoveries.index', 'recovery'))->assertOk()->assertSee('استرجاع مبرر');
});

test('a deleted relationship is restored under its original id', function () {
    $fixture = recoveryFamily();
    $edge = $fixture['child']->outgoingRelationships()->sole();
    $this->actingAs($fixture['admin'])->delete(route('management.relationships.destroy', ['recovery', $edge->id]))->assertSessionHasNoErrors();
    $log = latestLog(PersonRelationship::class, $edge->id, 'deleted');

    $this->get(route('management.audit.show', $log->id))->assertSee('قابل لـاستعادة المحذوف');
    recover($this, $fixture, $log)->assertSessionHasNoErrors();

    $restored = PersonRelationship::query()->findOrFail($edge->id);
    expect($restored->person_id)->toBe($fixture['child']->id)->and($restored->related_person_id)->toBe($fixture['parent']->id)
        ->and($restored->verification_status)->toBe('pending_review')
        ->and(AuditLog::query()->where('reverted_from_id', $log->id)->sole()->action)->toBe('restored');
});

test('restoring a relationship that would now create a cycle or a second father is refused', function () {
    $fixture = recoveryFamily();
    $edge = $fixture['child']->outgoingRelationships()->sole();
    $this->actingAs($fixture['admin'])->delete(route('management.relationships.destroy', ['recovery', $edge->id]))->assertSessionHasNoErrors();
    $log = latestLog(PersonRelationship::class, $edge->id, 'deleted');
    $fixture['parent']->outgoingRelationships()->sole()->delete();
    $fixture['tree']->personRelationships()->create(['person_id' => $fixture['parent']->id, 'related_person_id' => $fixture['child']->id, 'relationship_type' => 'father', 'is_primary' => true]);

    expect(fn () => app(AuditReversionService::class)->preview($log))->toThrow(ValidationException::class);
    $this->get(route('management.audit.revert.preview', $log->id))->assertOk()->assertSee('سلامة الأنساب')->assertDontSee('تأكيد الاستعادة');
    expect(PersonRelationship::query()->find($edge->id))->toBeNull();
});

test('a deleted branch returns only while its founder and slug are still valid', function () {
    $fixture = recoveryFamily();
    $branch = $fixture['tree']->branches()->create(['name_ar' => 'فرع قاسم', 'slug' => 'qasim', 'root_person_id' => $fixture['child']->id, 'is_active' => true, 'verification_status' => 'verified']);
    $this->actingAs($fixture['admin'])->delete(route('management.branches.destroy', ['recovery', $branch->id]))->assertSessionHasNoErrors();
    $log = latestLog(Branch::class, $branch->id, 'deleted');
    $blocker = $fixture['tree']->branches()->create(['name_ar' => 'فرع آخر', 'slug' => 'qasim', 'root_person_id' => $fixture['parent']->id, 'is_active' => false, 'verification_status' => 'pending_review']);

    $this->get(route('management.audit.revert.preview', $log->id))->assertSee('الرابط المختصر qasim مستخدم الآن');
    $blocker->delete();
    recover($this, $fixture, $log)->assertSessionHasNoErrors();
    expect(Branch::query()->findOrFail($branch->id)->verification_status)->toBe('pending_review');
});

test('a deleted person is restored with identity, privacy, living status and badges, but not republished', function () {
    $fixture = recoveryFamily();
    $badge = PersonAttribute::query()->create(['key' => 'restorable', 'label' => 'شارة', 'color' => '#123456', 'family_id' => $fixture['family']->id]);
    $person = $fixture['tree']->people()->create(['first_name_ar' => 'زيد', 'full_name_ar' => 'زيد المحذوف', 'canonical_key' => 'Z-1', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false, 'death_year' => 1800, 'notes' => 'ملاحظة مقيدة', 'field_visibility' => ['death_year' => 'restricted']]);
    $person->personAttributes()->attach($badge->id);
    $this->actingAs($fixture['admin'])->delete(route('management.people.destroy', ['recovery', $person->id]))->assertSessionHasNoErrors();
    $log = latestLog(Person::class, $person->id, 'deleted');

    $this->get(route('management.audit.revert.preview', $log->id))->assertOk()->assertSee('يُستعاد «قيد المراجعة»');
    recover($this, $fixture, $log)->assertSessionHasNoErrors();

    $restored = Person::query()->findOrFail($person->id);
    expect($restored->canonical_key)->toBe('Z-1')->and($restored->full_name_ar)->toBe('زيد المحذوف')
        ->and($restored->is_living)->toBeFalse()->and($restored->death_year)->toBe(1800)->and($restored->notes)->toBe('ملاحظة مقيدة')
        ->and($restored->field_visibility)->toBe(['death_year' => 'restricted'])
        ->and($restored->personAttributes()->pluck('person_attributes.id')->all())->toBe([$badge->id])
        ->and($restored->verification_status)->toBe('pending_review');
    auth()->logout();
    $this->get(route('genealogy.person', ['recovery', $person->id]))->assertNotFound();
});

test('a deletion without a complete snapshot is historical only', function () {
    $fixture = recoveryFamily();
    $log = AuditLog::query()->create(['family_id' => $fixture['family']->id, 'auditable_type' => Person::class, 'auditable_id' => 9999, 'action' => 'deleted', 'before' => ['id' => 9999, 'full_name_ar' => 'ناقص']]);

    $this->actingAs($fixture['admin'])->get(route('management.audit.show', $log->id))->assertSee('غير قابل للاسترجاع الآلي')->assertSee('لقطة كاملة');
    $this->post(route('management.audit.revert.store', $log->id), ['reason' => 'محاولة', 'confirm' => 1])->assertSessionHasErrors('change');
    expect(Person::query()->find(9999))->toBeNull();
});

test('reverting a privacy tightening or an unpublication needs an explicit scope confirmation', function () {
    $fixture = recoveryFamily();
    updateParent($this, $fixture, ['field_visibility' => ['birth_year' => 'restricted']])->assertSessionHasNoErrors();
    $privacyLog = latestLog(Person::class, $fixture['parent']->id, 'updated');

    recover($this, $fixture, $privacyLog)->assertSessionHasErrors('change');
    expect($fixture['parent']->fresh()->field_visibility)->toBe(['birth_year' => 'restricted']);
    $this->get(route('management.audit.revert.preview', $privacyLog->id))->assertSee('يوسّع الخصوصية الحالية للحقول: سنة الميلاد');
    recover($this, $fixture, $privacyLog, 'admin', ['confirm_scope' => 1])->assertSessionHasNoErrors();
    expect($fixture['parent']->fresh()->field_visibility)->toBe(['birth_year' => 'public']);

    updateParent($this, $fixture, ['verification_status' => 'pending_review', 'field_visibility' => ['birth_year' => 'public']])->assertSessionHasNoErrors();
    $publicationLog = latestLog(Person::class, $fixture['parent']->id, 'updated');
    expect(app(AuditReversionService::class)->preview($publicationLog)['publishes'])->toBeTrue();
    recover($this, $fixture, $publicationLog)->assertSessionHasErrors('change');
    expect($fixture['parent']->fresh()->verification_status)->toBe('pending_review');
});

test('reverting a living-status change returns it to unknown without inferring a death', function () {
    $fixture = recoveryFamily();
    $person = $fixture['tree']->people()->create(['first_name_ar' => 'مجهول', 'full_name_ar' => 'مجهول الحالة', 'status' => 'historical', 'verification_status' => 'pending_review']);
    $this->actingAs($fixture['admin'])->put(route('management.people.update', ['recovery', $person->id]), ['first_name_ar' => 'مجهول', 'full_name_ar' => 'مجهول الحالة', 'status' => 'historical', 'verification_status' => 'pending_review', 'is_living' => '0'])->assertSessionHasNoErrors();
    expect($person->fresh()->living_status_basis)->toBe('recorded');

    recover($this, $fixture, latestLog(Person::class, $person->id, 'updated'))->assertSessionHasNoErrors();
    expect($person->fresh()->is_living)->toBeNull()->and($person->fresh()->living_status_basis)->toBeNull();
});

test('a change since the preview is detected and nothing is overwritten', function () {
    $fixture = recoveryFamily();
    updateParent($this, $fixture, ['title' => 'أول'])->assertSessionHasNoErrors();
    $log = latestLog(Person::class, $fixture['parent']->id, 'updated');
    $fingerprint = app(AuditReversionService::class)->preview($log)['fingerprint'];
    Person::query()->whereKey($fixture['parent']->id)->update(['honorific' => 'تعديل خارج الواجهة']);

    $this->actingAs($fixture['admin'])->post(route('management.audit.revert.store', $log->id), ['reason' => 'x', 'confirm' => 1, 'fingerprint' => $fingerprint])->assertSessionHasErrors('change');
    updateParent($this, $fixture, ['title' => 'ثان'])->assertSessionHasNoErrors();
    expect(app(AuditReversionService::class)->eligibility($log)['eligible'])->toBeFalse()
        ->and($fixture['parent']->fresh()->title)->toBe('ثان');
});

test('a shared ancestor is recovered only by the super admin', function () {
    $fixture = recoveryFamily();
    $partner = recoveryFamily('partner');
    CrossFamilyLink::query()->create(['left_person_id' => $fixture['child']->id, 'right_person_id' => $partner['child']->id, 'left_family_id' => $fixture['family']->id, 'right_family_id' => $partner['family']->id, 'kind' => 'same_person', 'status' => 'approved', 'claim' => 'تطابق']);
    $this->actingAs($fixture['super'])->put(route('management.people.update', ['recovery', $fixture['parent']->id]), ['first_name_ar' => 'سليمان', 'full_name_ar' => 'سليمان', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '0', 'birth_year' => 1750, 'title' => 'مشترك'])->assertSessionHasNoErrors();
    $log = latestLog(Person::class, $fixture['parent']->id, 'updated');

    $this->actingAs($fixture['admin'])->get(route('management.audit.revert.preview', $log->id))->assertSee('عائلة partner');
    recover($this, $fixture, $log)->assertSessionHasErrors('change');
    recover($this, $fixture, $log, 'super')->assertSessionHasNoErrors();
    expect($fixture['parent']->fresh()->title)->toBeNull();
});

test('merges keep their own recovery path and merged records cannot be reverted generically', function () {
    $fixture = recoveryFamily();
    $duplicate = $fixture['tree']->people()->create(['first_name_ar' => 'قاسم', 'full_name_ar' => 'قاسم', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $fixture['tree']->personRelationships()->create(['person_id' => $duplicate->id, 'related_person_id' => $fixture['parent']->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $this->actingAs($fixture['admin'])->put(route('management.people.update', ['recovery', $duplicate->id]), ['first_name_ar' => 'قاسم', 'full_name_ar' => 'قاسم', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '0', 'title' => 'قبل الدمج', 'father_id' => $fixture['parent']->id])->assertSessionHasNoErrors();
    $updateLog = latestLog(Person::class, $duplicate->id, 'updated');
    $plan = app(PersonMergeService::class)->plan($fixture['tree'], $fixture['child']->id, $duplicate->id);
    $this->post(route('management.merges.store', 'recovery'), ['survivor' => $fixture['child']->id, 'merged' => $duplicate->id, 'fingerprint' => $plan['fingerprint'], 'reason' => 'مكرر', 'confirm' => 1])->assertSessionHasNoErrors();

    expect(app(AuditReversionService::class)->eligibility($updateLog)['eligible'])->toBeFalse()
        ->and(app(AuditReversionService::class)->eligibility(latestLog(Person::class, $duplicate->id, 'merged'))['reason'])->toContain('صفحة عملية الدمج');
    $this->post(route('management.merges.undo', ['recovery', PersonMerge::query()->sole()->id]), ['reason' => 'تراجع'])->assertSessionHasNoErrors();
    expect(Person::query()->find($duplicate->id))->not->toBeNull();
});

test('a change applied by accepting a claim is reversed by re-proposing the superseded claim', function () {
    $fixture = recoveryFamily();
    $this->actingAs($fixture['editor'])->post(route('management.assertions.store', ['recovery', $fixture['parent']->id]), ['fact_type' => 'full_name', 'value' => 'سليمان بن الجد', 'relation' => 'conflicting'])->assertSessionHasNoErrors();
    $this->post(route('management.assertions.propose', ['recovery', Assertion::query()->sole()->id]), ['reason' => 'رواية']);
    $this->actingAs($fixture['admin'])->post(route('management.changes.decide', ['recovery', ChangeRequest::query()->sole()->id]), ['decision' => 'accepted', 'decision_reason' => 'قبول'])->assertSessionHasNoErrors();
    $applied = AuditLog::query()->findOrFail(ChangeRequest::query()->sole()->applied_audit_id);

    expect(app(AuditReversionService::class)->eligibility($applied)['reason'])->toContain('اقترح اعتماد الرواية السابقة');
    $previous = Assertion::query()->where('state', 'superseded')->sole();
    $this->actingAs($fixture['editor'])->post(route('management.assertions.propose', ['recovery', $previous->id]), ['reason' => 'الاعتماد كان خطأ'])->assertRedirect();
    $this->actingAs($fixture['admin2'])->post(route('management.changes.decide', ['recovery', ChangeRequest::query()->latest('id')->first()->id]), ['decision' => 'accepted', 'decision_reason' => 'إعادة'])->assertSessionHasNoErrors();
    expect($fixture['parent']->fresh()->full_name_ar)->toBe('سليمان')->and($previous->fresh()->state)->toBe('authoritative')
        ->and(Assertion::query()->where('value', 'سليمان بن الجد')->sole()->state)->toBe('superseded');
});

test('only family administrators of this family can preview or perform a recovery', function (string $role) {
    $fixture = recoveryFamily();
    $fixture['outsider'] = recoveryFamily('elsewhere')['admin'];
    updateParent($this, $fixture, ['title' => 'لقب'])->assertSessionHasNoErrors();
    $log = latestLog(Person::class, $fixture['parent']->id, 'updated');

    $this->actingAs($fixture[$role])->get(route('management.audit.revert.preview', $log->id))->assertForbidden();
    $this->post(route('management.audit.revert.store', $log->id), ['reason' => 'x', 'confirm' => 1])->assertForbidden();
    $this->get(route('management.recoveries.index', 'recovery'))->assertForbidden();
    expect($fixture['parent']->fresh()->title)->toBe('لقب');
})->with(['reader', 'editor', 'outsider']);
