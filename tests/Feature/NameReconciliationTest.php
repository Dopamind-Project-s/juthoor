<?php

use App\Models\Assertion;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Imports\LegacySeeders\AlQawasmehBranchSeeder;
use App\Services\Imports\LegacySeeders\AlZoubiRootVariantSeeder;
use App\Services\Imports\LegacySeeders\FamilySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\LegacyGenealogySeeder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LegacyGenealogySeeder::class);
    $this->seed(AlQawasmehBranchSeeder::class);
    $this->seed(AlZoubiRootVariantSeeder::class);
    $this->slug = FamilySeeder::SLUG;
});

function demoUser(string $username): User
{
    return User::query()->where('username', $username)->sole();
}

function rootClaim(string $key, string $list = 'dataset'): Assertion
{
    return Assertion::query()->where('import_key', "root-variant:{$list}:{$key}")->sole();
}

/** @return array<int, array<string, mixed>> */
function genealogySnapshot(): array
{
    return [
        Person::query()->withoutGlobalScope('active')->orderBy('id')->get(['id', 'canonical_key', 'full_name_ar', 'alternate_names', 'merged_into_id'])->toArray(),
        PersonRelationship::query()->orderBy('id')->get(['id', 'person_id', 'related_person_id', 'relationship_type', 'is_primary'])->toArray(),
    ];
}

test('all 39 open ROOT differences are listed with their record, spelling, source and evidence', function () {
    $response = $this->actingAs(demoUser('editor'))->get(route('management.reconciliation.index', $this->slug))->assertOk();

    $open = Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->with('person')->get();
    expect($open)->toHaveCount(39);
    foreach ($open as $claim) {
        $response->assertSee('data-claim="'.$claim->import_key.'"', false)->assertSee($claim->value)->assertSee($claim->person->canonical_key);
    }
    $response->assertSee('تسلسل الجذر المشترك المقدم في 2026-10-16')
        ->assertDontSee('data-claim="root-variant:dataset:ROOT-077"', false);

    $this->get(route('management.reconciliation.index', [$this->slug, 'status' => 'decided']))->assertOk()
        ->assertSee('data-claim="root-variant:dataset:ROOT-077"', false)->assertSee('ذبيان')->assertSee('مجموعة البيانات المنسقة (الإصدار الرابع)');
    $this->get(route('management.reconciliation.index', [$this->slug, 'status' => 'bogus']))->assertSessionHasErrors('status');
});

test('readers and other families cannot open the reconciliation queue', function () {
    $this->actingAs(demoUser('reader'))->get(route('management.reconciliation.index', $this->slug))->assertForbidden();
    $outsider = User::factory()->create();
    Family::query()->create(['name_ar' => 'عائلة أخرى', 'slug' => 'other'])->memberships()->create(['user_id' => $outsider->id, 'role' => 'admin']);
    $this->actingAs($outsider)->get(route('management.reconciliation.index', $this->slug))->assertForbidden();
    $claim = Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->orderBy('id')->firstOrFail();
    $this->post(route('management.assertions.decline', [$this->slug, $claim->id]), ['reason' => 'x'])->assertForbidden();
    expect($claim->fresh()->state)->toBe('proposed');
});

test('importing the differences again changes nothing and never verifies a claim', function () {
    $before = [Assertion::query()->count(), Citation::query()->count(), AuditLog::query()->count(), genealogySnapshot()];

    $this->seed(AlZoubiRootVariantSeeder::class);

    expect([Assertion::query()->count(), Citation::query()->count(), AuditLog::query()->count(), genealogySnapshot()])->toBe($before)
        ->and(Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('verification_status', 'verified')->exists())->toBeFalse()
        ->and(Assertion::query()->where('import_key', 'like', 'root-variant:%')->whereIn('state', ['authoritative', 'rejected'])->exists())->toBeFalse()
        ->and(Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->count())->toBe(39);
});

test('an approved alternate spelling is added as a variant without changing the displayed name or identity', function () {
    $claim = Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->orderBy('id')->firstOrFail();
    $person = $claim->person;
    $editor = demoUser('editor');

    $this->actingAs($editor)->put(route('management.assertions.update', [$this->slug, $claim->id]), ['relation' => 'alternate_spelling', 'verification_status' => 'sourced'])->assertSessionHasNoErrors();
    $this->post(route('management.assertions.propose', [$this->slug, $claim->id]), ['reason' => 'اختلاف إملائي'])->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->where('assertion_id', $claim->id)->sole();
    expect($change->payload['alternate_names'])->toContain($claim->value)
        ->and($change->payload['full_name_ar'] ?? $person->full_name_ar)->toBe($person->full_name_ar);

    $this->actingAs(demoUser('familyadmin'))->post(route('management.changes.decide', [$this->slug, $change->id]), ['decision' => 'accepted', 'decision_reason' => 'تهجئة'])->assertSessionHasNoErrors();

    $fresh = $person->fresh();
    expect($fresh->full_name_ar)->toBe($person->full_name_ar)
        ->and($fresh->canonical_key)->toBe($person->canonical_key)
        ->and($fresh->alternate_names)->toContain($claim->value)
        ->and($claim->fresh()->state)->toBe('alternative')
        ->and(Assertion::query()->where('person_id', $person->id)->where('state', 'superseded')->exists())->toBeFalse()
        ->and(Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->count())->toBe(38);
});

test('an approved canonical correction changes the displayed name and keeps the previous name as a claim', function () {
    $claim = Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->orderBy('id')->firstOrFail();
    $person = $claim->person;
    $people = Person::query()->count();

    $this->actingAs(demoUser('editor'))->put(route('management.assertions.update', [$this->slug, $claim->id]), ['relation' => 'conflicting', 'verification_status' => 'sourced'])->assertSessionHasNoErrors();
    $this->post(route('management.assertions.propose', [$this->slug, $claim->id]), ['reason' => 'تصحيح موثق'])->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->where('assertion_id', $claim->id)->sole();
    $this->actingAs(demoUser('familyadmin'))->post(route('management.changes.decide', [$this->slug, $change->id]), ['decision' => 'accepted', 'decision_reason' => 'تصحيح'])->assertSessionHasNoErrors();

    expect($person->fresh()->full_name_ar)->toBe($claim->value)
        ->and($person->fresh()->id)->toBe($person->id)
        ->and(Person::query()->count())->toBe($people)
        ->and($claim->fresh()->state)->toBe('authoritative')
        ->and(Assertion::query()->where('person_id', $person->id)->where('state', 'superseded')->where('value', $person->full_name_ar)->exists())->toBeTrue();
});

test('a reviewer rejects a difference with a reason, keeping the claim and evidence', function () {
    $claim = Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->orderBy('id')->firstOrFail();
    $citations = $claim->citations()->count();
    $before = genealogySnapshot();

    $this->actingAs(demoUser('editor'))->post(route('management.assertions.decline', [$this->slug, $claim->id]), ['reason' => 'محاولة'])->assertForbidden();
    $this->actingAs(demoUser('familyadmin'))->post(route('management.assertions.decline', [$this->slug, $claim->id]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('management.assertions.decline', [$this->slug, $claim->id]), ['reason' => 'خطأ نسخ في القائمة'])->assertSessionHasNoErrors();

    $fresh = $claim->fresh();
    expect($fresh->state)->toBe('rejected')->and($fresh->review_reason)->toBe('خطأ نسخ في القائمة')
        ->and($fresh->citations()->count())->toBe($citations)
        ->and(genealogySnapshot())->toBe($before)
        ->and(AuditLog::query()->where('auditable_type', $claim->getMorphClass())->where('auditable_id', $claim->id)->where('action', 'rejected')->exists())->toBeTrue();

    $this->post(route('management.assertions.decline', [$this->slug, $claim->id]), ['reason' => 'مرة أخرى'])->assertSessionHasErrors('assertion');
    $this->get(route('management.reconciliation.index', [$this->slug, 'status' => 'decided']))->assertSee('خطأ نسخ في القائمة')->assertSee('سجل القرارات');
});

test('a claim under review cannot be declined directly', function () {
    $claim = Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->orderBy('id')->firstOrFail();
    $this->actingAs(demoUser('editor'))->post(route('management.assertions.propose', [$this->slug, $claim->id]), ['reason' => 'مقترح'])->assertSessionHasNoErrors();

    $this->actingAs(demoUser('familyadmin'))->post(route('management.assertions.decline', [$this->slug, $claim->id]), ['reason' => 'رفض'])->assertSessionHasErrors('assertion');
    expect($claim->fresh()->state)->toBe('proposed');
});

test('a difference can be left unresolved and stays open without changing the tree', function () {
    $claim = Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->orderBy('id')->firstOrFail();
    $before = genealogySnapshot();

    $this->actingAs(demoUser('editor'))->put(route('management.assertions.update', [$this->slug, $claim->id]), ['relation' => 'conflicting', 'verification_status' => 'unresolved'])->assertSessionHasNoErrors();
    $this->put(route('management.assertions.update', [$this->slug, $claim->id]), ['relation' => 'conflicting', 'verification_status' => 'verified'])->assertSessionHasErrors('verification_status');

    expect($claim->fresh()->state)->toBe('proposed')->and($claim->fresh()->verification_status)->toBe('unresolved')
        ->and(genealogySnapshot())->toBe($before);
    $this->get(route('management.reconciliation.index', [$this->slug, 'status' => 'unresolved']))->assertSee('data-claim="'.$claim->import_key.'"', false);
    $this->get(route('management.reconciliation.index', $this->slug))->assertDontSee('data-claim="'.$claim->import_key.'"', false);
    $this->get(route('management.families.dashboard', $this->slug))->assertSee('اختلافات أسماء تاريخية لم يُبت فيها');
});

test('reconciliation decisions keep ROOT-077, the ROOT-078 anchor identity and the Qawasmeh line intact', function () {
    $root77 = Person::query()->where('canonical_key', 'ROOT-077')->sole();
    $anchor = Person::query()->where('canonical_key', 'AZ-RAHAYMEH-SULAYMAN')->sole();
    $qawasmeh = PersonRelationship::query()->whereHas('person', fn ($query) => $query->where('canonical_key', 'like', 'QW-%'))->orderBy('id')->get(['id', 'person_id', 'related_person_id'])->toArray();
    $admin = demoUser('familyadmin');
    $claims = Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->orderBy('id')->get();

    foreach ($claims->take(5) as $claim) {
        $this->actingAs($admin)->post(route('management.assertions.decline', [$this->slug, $claim->id]), ['reason' => 'مراجعة'])->assertSessionHasNoErrors();
    }
    $this->actingAs(demoUser('editor'))->put(route('management.assertions.update', [$this->slug, $claims[5]->id]), ['relation' => 'alternate_spelling', 'verification_status' => 'pending_review']);
    $this->post(route('management.assertions.propose', [$this->slug, $claims[5]->id]), ['reason' => 'تهجئة']);
    $this->actingAs($admin)->post(route('management.changes.decide', [$this->slug, ChangeRequest::query()->sole()->id]), ['decision' => 'accepted', 'decision_reason' => 'تهجئة'])->assertSessionHasNoErrors();

    expect($root77->fresh()->full_name_ar)->toBe('ذيبان')
        ->and($root77->fresh()->alternate_names)->toContain('ذبيان')
        ->and(rootClaim('ROOT-077')->state)->toBe('alternative')
        ->and(Person::query()->where('canonical_key', 'ROOT-078')->exists())->toBeFalse()
        ->and($anchor->fresh()->canonical_key)->toBe('AZ-RAHAYMEH-SULAYMAN')
        ->and(Person::query()->where('canonical_key', 'QW-0001')->sole()->outgoingRelationships()->sole()->related_person_id)->toBe($anchor->id)
        ->and(PersonRelationship::query()->whereHas('person', fn ($query) => $query->where('canonical_key', 'like', 'QW-%'))->orderBy('id')->get(['id', 'person_id', 'related_person_id'])->toArray())->toBe($qawasmeh);
});

test('a difference on a shared ancestor is decided only by a platform reviewer', function () {
    $claim = Assertion::query()->where('import_key', 'like', 'root-variant:%')->where('state', 'proposed')->orderBy('id')->firstOrFail();
    $partner = Family::query()->create(['name_ar' => 'عائلة شريكة', 'slug' => 'partner']);
    $partnerTree = $partner->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $partnerPerson = $partnerTree->people()->create(['first_name_ar' => 'جد', 'full_name_ar' => 'جد', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    CrossFamilyLink::query()->create([
        'left_person_id' => $claim->person_id, 'right_person_id' => $partnerPerson->id,
        'left_family_id' => $claim->familyTree->family_id, 'right_family_id' => $partner->id,
        'kind' => 'same_person', 'status' => 'approved', 'claim' => 'الشخص نفسه',
    ]);

    $this->actingAs(demoUser('familyadmin'))->get(route('management.reconciliation.index', $this->slug))->assertSee('عائلة شريكة')->assertSee('جد مشترك');
    $this->post(route('management.assertions.decline', [$this->slug, $claim->id]), ['reason' => 'رفض محلي'])->assertSessionHasErrors('assertion');
    expect($claim->fresh()->state)->toBe('proposed');

    $this->actingAs(demoUser('superadmin'))->post(route('management.assertions.decline', [$this->slug, $claim->id]), ['reason' => 'مراجعة المنصة'])->assertSessionHasNoErrors();
    expect($claim->fresh()->state)->toBe('rejected');
});
