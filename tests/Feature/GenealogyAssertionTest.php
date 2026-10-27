<?php

use App\Models\Assertion;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\Source;
use App\Models\User;
use App\Services\Genealogy\BranchMembershipService;
use Database\Seeders\AlQawasmehBranchSeeder;
use Database\Seeders\AlZoubiRootVariantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\LegacyGenealogySeeder;

uses(RefreshDatabase::class);

/**
 * Root → ذيبان (published) → child; a second candidate father. One user per family role and a super admin.
 *
 * @return array<string, mixed>
 */
function factFamily(string $slug = 'facts'): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة '.$slug, 'slug' => $slug]);
    $tree = $family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $person = fn (string $name, array $extra = []) => $tree->people()->create($extra + ['first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $edge = fn (Person $child, Person $father) => $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $root = $person('الجد');
    $tree->update(['root_person_id' => $root->id]);
    $subject = $person('ذيبان', ['birth_year' => 1700, 'field_visibility' => ['birth_year' => 'restricted']]);
    $edge($subject, $root);
    $other = $person('سليمان');
    $edge($other, $root);
    $child = $person('قاسم');
    $edge($child, $subject);
    $source = $family->sources()->create(['source_type' => 'book', 'title' => 'كتاب الأنساب', 'verification_status' => 'sourced']);
    $chart = $family->sources()->create(['source_type' => 'document', 'title' => 'مشجرة العائلة', 'verification_status' => 'pending_review']);
    $users = [];
    foreach (['reader', 'editor', 'admin', 'admin2'] as $role) {
        $users[$role] = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $users[$role]->id, 'role' => $role === 'admin2' ? 'admin' : $role]);
    }

    return ['family' => $family, 'tree' => $tree->fresh(), 'root' => $root, 'subject' => $subject, 'other' => $other, 'child' => $child, 'source' => $source, 'chart' => $chart, ...$users];
}

function recordFact($test, array $fixture, string $role, array $input, ?Person $person = null)
{
    return $test->actingAs($fixture[$role])->post(route('management.assertions.store', [$fixture['family']->slug, ($person ?? $fixture['subject'])->id]), $input);
}

test('recording a claim keeps the active record unchanged and readers can only record unreviewed claims', function () {
    $fixture = factFamily();

    recordFact($this, $fixture, 'reader', ['fact_type' => 'full_name', 'value' => 'ذبيان', 'verification_status' => 'sourced'])->assertSessionHasNoErrors();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'title', 'value' => 'شيخ', 'verification_status' => 'sourced'])->assertSessionHasNoErrors();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'birth_year', 'value' => 'ليست سنة'])->assertSessionHasErrors('value');
    recordFact($this, $fixture, 'editor', ['fact_type' => 'title', 'value' => 'وجيه', 'verification_status' => 'verified'])->assertSessionHasErrors('verification_status');

    $claims = Assertion::query()->orderBy('id')->get();
    expect($claims)->toHaveCount(2)
        ->and($claims[0]->verification_status)->toBe('pending_review')->and($claims[0]->state)->toBe('proposed')
        ->and($claims[1]->verification_status)->toBe('sourced')
        ->and($fixture['subject']->fresh()->full_name_ar)->toBe('ذيبان')->and($fixture['subject']->fresh()->title)->toBeNull()
        ->and(AuditLog::query()->where('auditable_type', Assertion::class)->where('action', 'created')->count())->toBe(2);
});

test('one claim takes several citations and one source supports many claims without duplication', function () {
    $fixture = factFamily();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'full_name', 'value' => 'ذبيان'])->assertSessionHasNoErrors();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'title', 'value' => 'شيخ'], $fixture['child'])->assertSessionHasNoErrors();
    [$name, $title] = Assertion::query()->orderBy('id')->get()->all();
    $cite = fn (Assertion $claim, Source $source, string $locator) => $this->post(route('management.assertions.cite', ['facts', $claim->id]), ['source_id' => $source->id, 'locator' => $locator, 'verification_status' => 'sourced']);

    $cite($name, $fixture['source'], 'ص 12')->assertSessionHasNoErrors();
    $cite($name, $fixture['chart'], 'اللوحة 2')->assertSessionHasNoErrors();
    $cite($title, $fixture['source'], 'ص 40')->assertSessionHasNoErrors();
    $cite($name, $fixture['source'], 'ص 12')->assertSessionHasErrors('locator');
    $this->post(route('management.sources.cite', ['facts', $fixture['chart']->id]), ['target_type' => 'assertion', 'target_id' => $title->id, 'field_key' => 'assertion', 'locator' => 'اللوحة 5', 'verification_status' => 'pending_review'])->assertSessionHasNoErrors();

    expect($name->citations()->count())->toBe(2)->and($title->citations()->count())->toBe(2)
        ->and(Source::query()->count())->toBe(2)
        ->and($fixture['source']->citations()->whereNotNull('assertion_id')->count())->toBe(2);
    $this->actingAs($fixture['reader'])->post(route('management.assertions.cite', ['facts', $name->id]), ['source_id' => $fixture['source']->id, 'locator' => 'ص 99', 'verification_status' => 'pending_review'])->assertForbidden();
});

test('an accepted alternate spelling becomes a searchable variant without renaming the person', function () {
    $fixture = factFamily();
    recordFact($this, $fixture, 'reader', ['fact_type' => 'alternate_name', 'value' => 'ذبيان'])->assertSessionHasNoErrors();
    $claim = Assertion::query()->sole();
    expect($claim->relation)->toBe('alternate_spelling');

    $this->post(route('management.assertions.propose', ['facts', $claim->id]), ['reason' => 'ورد في المشجرة'])->assertRedirect();
    $change = ChangeRequest::query()->sole();
    $this->actingAs($fixture['admin'])->post(route('management.changes.decide', ['facts', $change->id]), ['decision' => 'accepted', 'decision_reason' => 'تهجئة معروفة'])->assertSessionHasNoErrors();

    $person = $fixture['subject']->fresh();
    expect($person->full_name_ar)->toBe('ذيبان')->and($person->alternate_names)->toBe(['ذبيان'])
        ->and($claim->fresh()->state)->toBe('alternative')->and($claim->fresh()->verification_status)->toBe('pending_review');
    auth()->logout();
    $this->getJson(route('genealogy.search', ['facts', 'q' => 'ذبيان']))->assertJsonFragment(['id' => $person->id]);
});

test('accepting a conflicting name replaces the display value, preserves the old value and keeps other accounts', function () {
    $fixture = factFamily();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'full_name', 'value' => 'ذيبان بن سليمان', 'relation' => 'conflicting'])->assertSessionHasNoErrors();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'full_name', 'value' => 'ذياب', 'relation' => 'conflicting'])->assertSessionHasNoErrors();
    [$first, $second] = Assertion::query()->orderBy('id')->get()->all();

    $this->post(route('management.assertions.propose', ['facts', $first->id]), ['reason' => 'مصدر منشور']);
    $this->actingAs($fixture['admin'])->post(route('management.changes.decide', ['facts', ChangeRequest::query()->sole()->id]), ['decision' => 'accepted', 'decision_reason' => 'رواية الكتاب أوثق'])->assertSessionHasNoErrors();

    $previous = Assertion::query()->where('state', 'superseded')->sole();
    expect($fixture['subject']->fresh()->full_name_ar)->toBe('ذيبان بن سليمان')
        ->and($first->fresh()->state)->toBe('authoritative')->and($first->fresh()->review_reason)->toBe('رواية الكتاب أوثق')
        ->and($previous->value)->toBe('ذيبان')->and($previous->fact_type)->toBe('full_name')
        ->and($second->fresh()->state)->toBe('proposed')
        ->and(Person::query()->count())->toBe(4);

    $this->actingAs($fixture['editor'])->post(route('management.assertions.propose', ['facts', $second->id]), ['reason' => 'رواية العائلة']);
    $this->actingAs($fixture['admin2'])->post(route('management.changes.decide', ['facts', ChangeRequest::query()->latest('id')->first()->id]), ['decision' => 'accepted', 'decision_reason' => 'وثيقة أحدث'])->assertSessionHasNoErrors();
    expect($first->fresh()->state)->toBe('superseded')->and($second->fresh()->state)->toBe('authoritative')
        ->and(Assertion::query()->where('state', 'superseded')->count())->toBe(2);
});

test('rejection keeps the claim with its reason and the record unchanged, and closed claims cannot be reclassified', function () {
    $fixture = factFamily();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'title', 'value' => 'أمير'])->assertSessionHasNoErrors();
    $claim = Assertion::query()->sole();
    $this->post(route('management.assertions.propose', ['facts', $claim->id]), ['reason' => 'رواية شفوية']);
    $this->post(route('management.assertions.propose', ['facts', $claim->id]), ['reason' => 'مكرر'])->assertSessionHasErrors('assertion');
    $change = ChangeRequest::query()->sole();
    $this->post(route('management.changes.decide', ['facts', $change->id]), ['decision' => 'accepted', 'decision_reason' => 'أعتمد مقترحي'])->assertForbidden();

    $this->actingAs($fixture['admin'])->post(route('management.changes.decide', ['facts', $change->id]), ['decision' => 'rejected', 'decision_reason' => 'لا مصدر مكتوب'])->assertSessionHasNoErrors();

    expect($claim->fresh()->state)->toBe('rejected')->and($claim->fresh()->review_reason)->toBe('لا مصدر مكتوب')
        ->and($fixture['subject']->fresh()->title)->toBeNull();
    $this->put(route('management.assertions.update', ['facts', $claim->id]), ['relation' => 'additional', 'verification_status' => 'sourced'])->assertSessionHasErrors('assertion');
    $this->post(route('management.assertions.propose', ['facts', $claim->id]), ['reason' => 'مجددًا'])->assertSessionHasErrors('assertion');
});

test('a competing father claim never touches the active graph until accepted, then moves the line safely', function () {
    $fixture = factFamily();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'father', 'related_person_id' => $fixture['other']->id, 'relation' => 'conflicting'], $fixture['child'])->assertSessionHasNoErrors();
    $claim = Assertion::query()->sole();
    $branch = $fixture['tree']->branches()->create(['name_ar' => 'فخذ ذيبان', 'slug' => 'dhiban', 'root_person_id' => $fixture['subject']->id, 'is_active' => true, 'verification_status' => 'verified'])->fresh();

    expect($fixture['child']->outgoingRelationships()->sole()->related_person_id)->toBe($fixture['subject']->id)
        ->and(app(BranchMembershipService::class)->members($branch)->modelKeys())->toContain($fixture['child']->id);
    $this->get(route('genealogy.person', ['facts', $fixture['child']->id]))->assertOk()->assertSee('ذيبان')->assertDontSee('سليمان');

    $this->post(route('management.assertions.propose', ['facts', $claim->id]), ['reason' => 'مشجرة أخرى']);
    $this->actingAs($fixture['admin'])->post(route('management.changes.decide', ['facts', ChangeRequest::query()->sole()->id]), ['decision' => 'accepted', 'decision_reason' => 'ثبت بالمصدر'])->assertSessionHasNoErrors();

    expect($fixture['child']->outgoingRelationships()->count())->toBe(1)
        ->and($fixture['child']->outgoingRelationships()->sole()->related_person_id)->toBe($fixture['other']->id)
        ->and(app(BranchMembershipService::class)->members($branch)->modelKeys())->not->toContain($fixture['child']->id)
        ->and(Assertion::query()->where('state', 'superseded')->sole()->related_person_id)->toBe($fixture['subject']->id);
});

test('self, cyclic and duplicate parentage claims are refused when recorded', function () {
    $fixture = factFamily();

    recordFact($this, $fixture, 'editor', ['fact_type' => 'father', 'related_person_id' => $fixture['subject']->id])->assertSessionHasErrors('related_person_id');
    recordFact($this, $fixture, 'editor', ['fact_type' => 'father', 'related_person_id' => $fixture['child']->id])->assertSessionHasErrors('related_person_id');
    recordFact($this, $fixture, 'editor', ['fact_type' => 'father', 'related_person_id' => $fixture['other']->id])->assertSessionHasNoErrors();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'father', 'related_person_id' => $fixture['other']->id])->assertSessionHasErrors('value');
    expect(Assertion::query()->count())->toBe(1);
});

test('another family cannot record, cite, classify or propose on these claims', function () {
    $fixture = factFamily();
    $outsider = factFamily('elsewhere')['admin'];
    recordFact($this, $fixture, 'editor', ['fact_type' => 'title', 'value' => 'شيخ'])->assertSessionHasNoErrors();
    $claim = Assertion::query()->sole();
    $this->actingAs($outsider);

    $this->post(route('management.assertions.store', ['facts', $fixture['subject']->id]), ['fact_type' => 'title', 'value' => 'دخيل'])->assertForbidden();
    $this->post(route('management.assertions.propose', ['facts', $claim->id]), ['reason' => 'دخيل'])->assertForbidden();
    $this->put(route('management.assertions.update', ['elsewhere', $claim->id]), ['relation' => 'additional', 'verification_status' => 'sourced'])->assertNotFound();
    $this->post(route('management.assertions.store', ['elsewhere', $fixture['subject']->id]), ['fact_type' => 'title', 'value' => 'دخيل'])->assertNotFound();
    $this->get(route('management.people.show', ['facts', $fixture['subject']->id]))->assertForbidden();
});

test('claims and their evidence never appear on public pages or JSON', function () {
    $fixture = factFamily();
    recordFact($this, $fixture, 'editor', ['fact_type' => 'full_name', 'value' => 'اسم مروي سري'])->assertSessionHasNoErrors();
    $this->post(route('management.assertions.cite', ['facts', Assertion::query()->sole()->id]), ['source_id' => $fixture['source']->id, 'locator' => 'ص 7', 'verification_status' => 'sourced']);
    auth()->logout();

    $this->get(route('genealogy.person', ['facts', $fixture['subject']->id]))->assertOk()->assertDontSee('اسم مروي سري')->assertDontSee('كتاب الأنساب');
    foreach ([route('genealogy.graph', ['facts', 'all' => 1]), route('genealogy.search', ['facts', 'q' => 'اسم مروي'])] as $url) {
        expect($this->getJson($url)->assertOk()->getContent())->not->toContain('اسم مروي سري')->not->toContain('كتاب الأنساب');
    }
});

test('claims about restricted fields are masked for members without access, including audit entries', function () {
    $fixture = factFamily();
    recordFact($this, $fixture, 'admin', ['fact_type' => 'birth_year', 'value' => '1688', 'notes' => 'تاريخ من مخطوط'])->assertSessionHasNoErrors();
    $claim = Assertion::query()->sole();
    $this->post(route('management.assertions.cite', ['facts', $claim->id]), ['source_id' => $fixture['source']->id, 'locator' => 'ورقة 3', 'notes' => 'يذكر 1688', 'verification_status' => 'sourced']);
    $log = AuditLog::query()->where('auditable_type', Assertion::class)->sole();

    $this->get(route('management.people.show', ['facts', $fixture['subject']->id]))->assertSee('1688');
    foreach (['reader', 'editor'] as $role) {
        $this->actingAs($fixture[$role]);
        $this->get(route('management.people.show', ['facts', $fixture['subject']->id]))->assertOk()->assertDontSee('1688')->assertDontSee('تاريخ من مخطوط')->assertSee('محجوب');
        $this->get(route('management.sources.show', ['facts', $fixture['source']->id]))->assertOk()->assertDontSee('1688');
        $this->get(route('management.audit.show', $log->id))->assertOk()->assertDontSee('1688');
    }
    $this->actingAs($fixture['admin'])->post(route('management.assertions.propose', ['facts', $claim->id]), ['reason' => 'مخطوط'])->assertRedirect();
    $change = ChangeRequest::query()->sole();
    $this->actingAs($fixture['reader'])->get(route('management.changes.show', ['facts', $change->id]))->assertOk()->assertDontSee('1688')->assertDontSee('1700')->assertSee('محجوب');
    $this->actingAs($fixture['admin2'])->get(route('management.changes.show', ['facts', $change->id]))->assertOk()->assertSee('1688');
});

test('a shared ancestor fact is changed only by a platform reviewer and its impacted families are named', function () {
    $fixture = factFamily();
    $partner = factFamily('partner');
    CrossFamilyLink::query()->create([
        'left_person_id' => $fixture['child']->id, 'right_person_id' => $partner['child']->id,
        'left_family_id' => $fixture['family']->id, 'right_family_id' => $partner['family']->id,
        'kind' => 'same_person', 'status' => 'approved', 'claim' => 'الشخص نفسه',
    ]);
    recordFact($this, $fixture, 'editor', ['fact_type' => 'title', 'value' => 'جد مشترك'])->assertSessionHasNoErrors();
    $this->post(route('management.assertions.propose', ['facts', Assertion::query()->sole()->id]), ['reason' => 'لقب']);
    $change = ChangeRequest::query()->sole();

    $this->actingAs($fixture['admin'])->get(route('management.changes.show', ['facts', $change->id]))->assertSee('العائلات المتأثرة')->assertSee('عائلة partner');
    $this->post(route('management.changes.decide', ['facts', $change->id]), ['decision' => 'accepted', 'decision_reason' => 'قبول محلي'])->assertSessionHasErrors('change');
    $this->put(route('management.people.update', ['facts', $fixture['subject']->id]), ['first_name_ar' => 'ذيبان', 'full_name_ar' => 'تعديل محلي', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '0'])->assertSessionHasErrors('change');
    expect($fixture['subject']->fresh()->title)->toBeNull()->and($change->fresh()->status)->toBe('pending');

    $super = User::factory()->create()->forceFill(['is_super_admin' => true]);
    $super->save();
    $this->actingAs($super)->post(route('management.changes.decide', ['facts', $change->id]), ['decision' => 'accepted', 'decision_reason' => 'مراجعة المنصة'])->assertSessionHasNoErrors();
    expect($fixture['subject']->fresh()->title)->toBe('جد مشترك')
        ->and($fixture['other']->fresh()->title)->toBeNull();
    $this->actingAs($fixture['admin'])->put(route('management.people.update', ['facts', $fixture['other']->id]), ['first_name_ar' => 'سليمان', 'full_name_ar' => 'سليمان', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '0', 'title' => 'غير مشترك'])->assertSessionHasNoErrors();
});

test('ROOT name differences are recorded once for review without changing any person', function () {
    $this->seed(LegacyGenealogySeeder::class);
    $this->seed(AlQawasmehBranchSeeder::class);
    $people = Person::query()->orderBy('id')->get(['id', 'full_name_ar', 'alternate_names'])->toArray();

    $this->seed(AlZoubiRootVariantSeeder::class);
    $this->seed(AlZoubiRootVariantSeeder::class);

    $root77 = Assertion::query()->where('import_key', 'root-variant:dataset:ROOT-077')->sole();
    expect(Assertion::query()->where('state', 'proposed')->where('relation', 'unclassified')->count())->toBe(39)
        ->and($root77->fact_type)->toBe('alternate_name')->and($root77->state)->toBe('alternative')->and($root77->value)->toBe('ذبيان')
        ->and(Citation::query()->whereNotNull('assertion_id')->count())->toBe(40)
        ->and(Assertion::query()->where('verification_status', 'verified')->exists())->toBeFalse()
        ->and(Person::query()->orderBy('id')->get(['id', 'full_name_ar', 'alternate_names'])->toArray())->toBe($people);
});
