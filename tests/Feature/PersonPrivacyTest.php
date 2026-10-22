<?php

use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Services\Genealogy\LivingStatusClassifier;
use App\Services\Genealogy\PersonPrivacy;
use App\Services\Imports\CsvImportService;
use Database\Seeders\AlQawasmehBranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A published deceased ancestor with one public, one family-level and one restricted fact, plus a verified historical
 * child whose living status is unknown. Users: one per family role, a super admin and an admin of another family.
 *
 * @return array<string, mixed>
 */
function privacyFixture(): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة الخصوصية', 'slug' => 'privacy']);
    $tree = $family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $ancestor = $tree->people()->create([
        'first_name_ar' => 'الجد', 'full_name_ar' => 'الجد المنشور', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false,
        'birth_year' => 1801, 'death_year' => 1877, 'gender' => 'male', 'notes' => 'ملاحظة سرية جدا',
        'field_visibility' => ['birth_year' => 'public'],
    ]);
    $tree->update(['root_person_id' => $ancestor->id]);
    $unknown = $tree->people()->create(['first_name_ar' => 'مجهول', 'full_name_ar' => 'ابن مجهول الحالة', 'status' => 'historical', 'verification_status' => 'verified', 'birth_year' => 1840]);
    $tree->personRelationships()->create(['person_id' => $unknown->id, 'related_person_id' => $ancestor->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $grandchild = $tree->people()->create(['first_name_ar' => 'حفيد', 'full_name_ar' => 'حفيد منشور', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $tree->personRelationships()->create(['person_id' => $grandchild->id, 'related_person_id' => $unknown->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $users = [];
    foreach (['reader', 'editor', 'admin'] as $role) {
        $users[$role] = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $users[$role]->id, 'role' => $role]);
    }
    $users['super'] = User::factory()->create()->forceFill(['is_super_admin' => true]);
    $users['super']->save();
    $other = Family::query()->create(['name_ar' => 'عائلة أخرى', 'slug' => 'other-privacy']);
    $users['outsider'] = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $other->id, 'user_id' => $users['outsider']->id, 'role' => 'admin']);

    return ['family' => $family, 'tree' => $tree->fresh(), 'ancestor' => $ancestor, 'unknown' => $unknown, 'grandchild' => $grandchild, ...$users];
}

test('an unknown living status is private everywhere even for a verified historical record', function () {
    $fixture = privacyFixture();
    $unknown = $fixture['unknown'];

    expect($unknown->living_status)->toBe('unknown')
        ->and(Person::query()->publiclyVisible()->whereKey($unknown->id)->exists())->toBeFalse();
    $this->get(route('genealogy.person', ['privacy', $unknown->id]))->assertNotFound();
    expect(collect($this->getJson(route('genealogy.graph', ['privacy', 'all' => 1]))->json('nodes'))->pluck('id'))->not->toContain($unknown->id);
    $this->getJson(route('genealogy.search', ['privacy', 'q' => 'مجهول']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('genealogy.search', ['privacy', 'q' => 'حفيد']))->assertOk()->assertJsonPath('data.0.lineage', null);
    $this->get(route('genealogy.person', ['privacy', $fixture['grandchild']->id]))->assertOk()->assertDontSee('ابن مجهول الحالة');
});

test('explicitly deceased records stay published and editing other fields keeps their status', function () {
    $fixture = privacyFixture();

    $this->get(route('genealogy.person', ['privacy', $fixture['ancestor']->id]))->assertOk();
    $this->actingAs($fixture['admin'])->put(route('management.people.update', ['privacy', $fixture['ancestor']->id]), [
        'first_name_ar' => 'الجد', 'full_name_ar' => 'الجد المنشور', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '0', 'title' => 'لقب جديد',
    ])->assertSessionHasNoErrors();
    expect($fixture['ancestor']->fresh()->is_living)->toBeFalse()->and($fixture['ancestor']->fresh()->title)->toBe('لقب جديد');
});

test('the person form keeps an unknown status unknown instead of recording a death', function () {
    $fixture = privacyFixture();
    $this->actingAs($fixture['admin'])->post(route('management.people.store', 'privacy'), [
        'first_name_ar' => 'جديد', 'full_name_ar' => 'شخص جديد', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '',
    ])->assertSessionHasNoErrors();

    $person = $fixture['tree']->people()->where('full_name_ar', 'شخص جديد')->sole();
    expect($person->is_living)->toBeNull()->and($person->living_status_basis)->toBeNull();
    $this->put(route('management.people.update', ['privacy', $person->id]), [
        'first_name_ar' => 'جديد', 'full_name_ar' => 'شخص جديد', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '0',
    ])->assertSessionHasNoErrors();
    expect($person->fresh()->is_living)->toBeFalse()->and($person->fresh()->living_status_basis)->toBe(LivingStatusClassifier::BASIS_RECORDED);
});

test('the public person page shows only fields set to public', function () {
    $fixture = privacyFixture();

    $this->get(route('genealogy.person', ['privacy', $fixture['ancestor']->id]))->assertOk()
        ->assertSee('سنة الميلاد')->assertSee('1801')
        ->assertDontSee('1877')->assertDontSee('سنة الوفاة')->assertDontSee('ملاحظة سرية جدا')->assertDontSee('ذكر');
});

test('family members read family-level fields, only administrators read restricted fields', function (string $role, bool $family, bool $restricted) {
    $fixture = privacyFixture();
    $page = $this->actingAs($fixture[$role])->get(route('management.people.show', ['privacy', $fixture['ancestor']->id]))->assertOk();

    $family ? $page->assertSee('1877') : $page->assertDontSee('1877');
    $restricted ? $page->assertSee('ملاحظة سرية جدا') : $page->assertDontSee('ملاحظة سرية جدا')->assertSee('محجوب');
    $restricted ? $this->get(route('management.records.show', ['privacy', 'people', $fixture['ancestor']->id]))->assertSee('ملاحظة سرية جدا')
        : $this->get(route('management.records.show', ['privacy', 'people', $fixture['ancestor']->id]))->assertDontSee('ملاحظة سرية جدا');
})->with([
    'reader' => ['reader', true, false],
    'editor' => ['editor', true, false],
    'family admin' => ['admin', true, true],
    'super admin' => ['super', true, true],
]);

test('the private preview uses the member audience and the public view the visitor audience', function () {
    $fixture = privacyFixture();
    $url = route('genealogy.person', ['privacy', $fixture['ancestor']->id, 'private' => 1]);

    $this->actingAs($fixture['reader'])->get($url)->assertOk()->assertSee('1877')->assertDontSee('ملاحظة سرية جدا');
    $this->get(route('genealogy.person', ['privacy', $fixture['ancestor']->id]))->assertOk()->assertDontSee('1877');
});

test('another family cannot read private fields through management or preview routes', function () {
    $fixture = privacyFixture();
    $this->actingAs($fixture['outsider']);

    $this->get(route('management.people.show', ['privacy', $fixture['ancestor']->id]))->assertForbidden();
    $this->get(route('genealogy.person', ['privacy', $fixture['ancestor']->id, 'private' => 1]))->assertForbidden();
    $this->get(route('genealogy.person', ['privacy', $fixture['ancestor']->id]))->assertOk()->assertDontSee('1877')->assertDontSee('ملاحظة سرية جدا');
    expect(app(PersonPrivacy::class)->audience($fixture['outsider'], $fixture['family']))->toBe(PersonPrivacy::PUBLIC);
});

test('graph, search and lookup JSON never carry sensitive fields', function () {
    $fixture = privacyFixture();
    $responses = [
        $this->getJson(route('genealogy.graph', 'privacy')),
        $this->getJson(route('genealogy.graph', ['privacy', 'all' => 1])),
        $this->getJson(route('genealogy.search', ['privacy', 'q' => 'الجد'])),
        $this->actingAs($fixture['reader'])->getJson(route('genealogy.graph', ['privacy', 'all' => 1, 'private' => 1])),
        $this->getJson(route('management.lookups.people', 'privacy')),
    ];

    foreach ($responses as $response) {
        $body = $response->assertOk()->getContent();
        foreach (['1801', '1877', 'ملاحظة سرية', 'birth_year', 'death_year', 'notes', 'gender', 'field_visibility'] as $needle) {
            expect($body)->not->toContain($needle);
        }
    }
});

test('search does not index private field values', function () {
    privacyFixture();

    $this->getJson(route('genealogy.search', ['privacy', 'q' => 'ملاحظة سرية']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('genealogy.search', ['privacy', 'q' => '1877']))->assertOk()->assertJsonCount(0, 'data');
});

test('editors cannot read restricted values in forms or change privacy levels, and their edits keep hidden values', function () {
    $fixture = privacyFixture();
    $fixture['ancestor']->update(['verification_status' => 'pending_review', 'field_visibility' => ['birth_year' => 'restricted']]);
    $this->actingAs($fixture['editor']);

    $this->get(route('management.records.edit', ['privacy', 'people', $fixture['ancestor']->id]))->assertOk()
        ->assertDontSee('1801')->assertDontSee('ملاحظة سرية جدا')->assertDontSee('name="field_visibility', false)->assertSee('محجوب بحسب إعداد الخصوصية');
    $this->put(route('management.people.update', ['privacy', $fixture['ancestor']->id]), [
        'first_name_ar' => 'الجد', 'full_name_ar' => 'الجد المعدل', 'status' => 'historical', 'verification_status' => 'pending_review', 'is_living' => '0',
        'field_visibility' => ['birth_year' => 'public', 'notes' => 'public'],
    ])->assertSessionHasNoErrors();

    $person = $fixture['ancestor']->fresh();
    expect($person->full_name_ar)->toBe('الجد المعدل')->and($person->birth_year)->toBe(1801)
        ->and($person->notes)->toBe('ملاحظة سرية جدا')->and($person->field_visibility)->toBe(['birth_year' => 'restricted']);
});

test('administrators set field privacy and readers cannot see it in audit or proposal diffs', function () {
    $fixture = privacyFixture();
    $this->actingAs($fixture['admin'])->get(route('management.records.edit', ['privacy', 'people', $fixture['ancestor']->id]))->assertSee('name="field_visibility[notes]"', false);
    $this->put(route('management.people.update', ['privacy', $fixture['ancestor']->id]), [
        'first_name_ar' => 'الجد', 'full_name_ar' => 'الجد المنشور', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '0',
        'birth_year' => 1801, 'death_year' => 1877, 'gender' => 'male', 'field_visibility' => ['death_year' => 'restricted', 'unknown_field' => 'public'],
    ])->assertSessionHasNoErrors();
    expect($fixture['ancestor']->fresh()->field_visibility)->toBe(['death_year' => 'restricted']);

    $log = AuditLog::query()->where('auditable_type', Person::class)->where('auditable_id', $fixture['ancestor']->id)->latest('id')->firstOrFail();
    $this->actingAs($fixture['reader'])->get(route('management.audit.show', $log->id))->assertOk()->assertDontSee('1877')->assertDontSee('ملاحظة سرية جدا')->assertSee('محجوب');
    $this->actingAs($fixture['admin'])->get(route('management.audit.show', $log->id))->assertOk()->assertSee('1877');

    $this->actingAs($fixture['reader'])->post(route('management.changes.store', 'privacy'), [
        'kind' => 'person', 'operation' => 'update', 'target_id' => $fixture['ancestor']->id, 'reason' => 'تصحيح', 'title' => 'لقب', 'field_visibility' => ['notes' => 'public'],
    ])->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    expect($change->payload)->not->toHaveKey('field_visibility.notes');
    $this->get(route('management.changes.show', ['privacy', $change->id]))->assertOk()->assertDontSee('1877')->assertDontSee('ملاحظة سرية جدا');
});

test('the classifier only resolves unknown records that carry explicit evidence', function () {
    $tree = privacyFixture()['tree'];
    $person = fn (string $name, array $extra = []) => $tree->people()->create($extra + ['first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'verification_status' => 'verified']);
    $historical = $person('تاريخي موثق');
    $recent = $person('مولود حديثا', ['birth_year' => (int) now()->year - 30]);
    $death = $person('وفاة مسجلة', ['verification_status' => 'pending_review', 'death_year' => 1950]);
    $pending = $person('قيد المراجعة', ['verification_status' => 'pending_review']);
    $active = $person('نشط', ['status' => 'active']);
    $living = $person('حي', ['is_living' => true]);

    $counts = app(LivingStatusClassifier::class)->classifyUnknown($tree->id);

    expect($historical->fresh()->is_living)->toBeFalse()->and($historical->fresh()->living_status_basis)->toBe(LivingStatusClassifier::BASIS_HISTORICAL_RECORD)
        ->and($death->fresh()->is_living)->toBeFalse()->and($death->fresh()->living_status_basis)->toBe(LivingStatusClassifier::BASIS_DEATH_RECORDED)
        ->and($recent->fresh()->is_living)->toBeNull()
        ->and($pending->fresh()->is_living)->toBeNull()
        ->and($active->fresh()->is_living)->toBeNull()
        ->and($living->fresh()->is_living)->toBeTrue()
        ->and($counts['death_recorded'])->toBe(1)
        ->and(Person::query()->publiclyVisible()->whereKey($death->id)->exists())->toBeFalse();
});

test('the seeded published genealogy remains public while the Qawasmeh records stay unknown', function () {
    $this->seed();
    $this->seed(AlQawasmehBranchSeeder::class);

    expect(Person::query()->where('canonical_key', 'like', 'ROOT-%')->where('living_status_basis', LivingStatusClassifier::BASIS_HISTORICAL_RECORD)->count())->toBe(77)
        ->and(Person::query()->publiclyVisible()->count())->toBe(85)
        ->and(Person::query()->where('canonical_key', 'like', 'QW-%')->whereNull('is_living')->count())->toBe(34);
    $this->getJson(route('genealogy.graph', ['al-zoubi', 'all' => 1]))->assertOk()->assertJsonCount(85, 'nodes');
});

test('a CSV row without a living status is imported as unknown', function () {
    Storage::fake('genealogy_private');
    $tree = privacyFixture()['tree'];
    Storage::disk('genealogy_private')->put('imports/test.csv', implode(',', CsvImportService::COLUMNS)."\nP1,جذر,جذر مستورد,male,1900,,,historical,verified,,,\n");

    $rows = app(CsvImportService::class)->analyze($tree, 'imports/test.csv');

    expect($rows[0]['errors'])->toBe([])->and($rows[0]['data'])->toHaveKey('is_living')->and($rows[0]['data']['is_living'])->toBeNull();
});
