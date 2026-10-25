<?php

use App\Models\Assertion;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonMerge;
use App\Models\User;
use App\Services\Genealogy\BranchIntegrityService;
use App\Services\Genealogy\PersonMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Root → father → survivor «محمد» (published) and duplicate «محمد» (pending, with a child, a branch, evidence, a
 * badge and a claim). Users per family role, a second admin and a super admin.
 *
 * @return array<string, mixed>
 */
function mergeFixture(string $slug = 'merge'): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة '.$slug, 'slug' => $slug]);
    $tree = $family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $person = fn (string $name, array $extra = []) => $tree->people()->create($extra + ['first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $edge = fn (Person $child, Person $father, array $extra = []) => $tree->personRelationships()->create($extra + ['person_id' => $child->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $root = $person('الجد');
    $tree->update(['root_person_id' => $root->id]);
    $father = $person('علي');
    $edge($father, $root);
    $survivor = $person('محمد', ['birth_year' => 1800, 'field_visibility' => ['birth_year' => 'family']]);
    $edge($survivor, $father);
    $duplicate = $person('محمد', ['verification_status' => 'pending_review', 'birth_year' => 1800, 'death_year' => 1870, 'field_visibility' => ['birth_year' => 'restricted']]);
    $duplicateEdge = $edge($duplicate, $father, ['verification_status' => 'pending_review']);
    $child = $person('حسن');
    $edge($child, $duplicate);
    $branch = $tree->branches()->create(['name_ar' => 'فرع حسن', 'slug' => 'hasan', 'root_person_id' => $duplicate->id, 'is_active' => false, 'verification_status' => 'pending_review']);
    $source = $family->sources()->create(['source_type' => 'book', 'title' => 'كتاب', 'verification_status' => 'sourced']);
    $source->citations()->create(['family_tree_id' => $tree->id, 'person_id' => $duplicate->id, 'field_key' => 'person', 'verification_status' => 'sourced']);
    $source->citations()->create(['family_tree_id' => $tree->id, 'person_relationship_id' => $duplicateEdge->id, 'field_key' => 'father', 'verification_status' => 'sourced']);
    $duplicate->sources()->attach($source->id, ['relation_type' => 'record']);
    $survivor->sources()->attach($source->id, ['relation_type' => 'record']);
    $badge = PersonAttribute::query()->create(['key' => 'badge-'.$slug, 'label' => 'شارة', 'color' => '#123456', 'family_id' => $family->id]);
    $duplicate->personAttributes()->attach($badge->id);
    $tree->assertions()->create(['person_id' => $duplicate->id, 'fact_type' => 'title', 'value' => 'شيخ', 'state' => 'proposed']);
    $users = [];
    foreach (['reader', 'editor', 'admin', 'admin2'] as $role) {
        $users[$role] = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $users[$role]->id, 'role' => $role === 'admin2' ? 'admin' : $role]);
    }
    $users['super'] = User::factory()->create()->forceFill(['is_super_admin' => true]);
    $users['super']->save();

    return ['family' => $family, 'tree' => $tree->fresh(), 'root' => $root, 'father' => $father, 'survivor' => $survivor, 'duplicate' => $duplicate, 'child' => $child, 'branch' => $branch, 'source' => $source, ...$users];
}

/** @return array<string, array<int, array<string, mixed>>> every table a merge may touch */
function mergeState(): array
{
    $state = [];
    foreach (['people', 'person_relationships', 'branches', 'citations', 'person_sources', 'person_attribute_assignments', 'assertions', 'cross_family_links', 'family_trees'] as $table) {
        $state[$table] = DB::table($table)->orderBy($table === 'person_attribute_assignments' ? 'person_id' : 'id')->get()->map(fn ($row) => array_map(fn ($value) => $value === null ? null : (string) (is_bool($value) ? (int) $value : $value), (array) $row))->all();
    }

    return $state;
}

function previewMerge($test, array $fixture, string $role = 'admin', array $decisions = [], ?Person $survivor = null, ?Person $merged = null)
{
    return $test->actingAs($fixture[$role])->get(route('management.merges.preview', [$fixture['family']->slug, 'survivor' => ($survivor ?? $fixture['survivor'])->id, 'merged' => ($merged ?? $fixture['duplicate'])->id, 'decisions' => $decisions]));
}

function executeMerge($test, array $fixture, string $role = 'admin', array $decisions = [], ?string $fingerprint = null)
{
    $fingerprint ??= app(PersonMergeService::class)->plan($fixture['tree'], $fixture['survivor']->id, $fixture['duplicate']->id, $decisions)['fingerprint'];

    return $test->actingAs($fixture[$role])->post(route('management.merges.store', $fixture['family']->slug), [
        'survivor' => $fixture['survivor']->id, 'merged' => $fixture['duplicate']->id, 'decisions' => $decisions, 'fingerprint' => $fingerprint, 'reason' => 'سجلان لشخص واحد', 'confirm' => 1,
    ]);
}

test('same-name siblings are listed as candidates for every member while only administrators may preview', function () {
    $fixture = mergeFixture();
    $fixture['tree']->people()->create(['first_name_ar' => 'محمد', 'full_name_ar' => 'محمد', 'status' => 'historical', 'verification_status' => 'verified']);

    $this->actingAs($fixture['reader'])->get(route('management.merges.index', 'merge'))->assertOk()
        ->assertSee('#'.$fixture['survivor']->id)->assertSee('#'.$fixture['duplicate']->id)->assertDontSee('معاينة: إبقاء')->assertDontSee('1800');
    previewMerge($this, $fixture, 'reader')->assertForbidden();
    previewMerge($this, $fixture, 'editor')->assertForbidden();
    $this->actingAs($fixture['admin'])->get(route('management.merges.index', 'merge'))->assertSee('معاينة: إبقاء #'.$fixture['survivor']->id);
});

test('the preview reports every consequence and changes nothing', function () {
    $fixture = mergeFixture();
    $before = mergeState();

    previewMerge($this, $fixture)->assertOk()
        ->assertSee('أبناء السجل المدموج (1)')->assertSee('فروع يؤسسها السجل المدموج (1)')->assertSee('استشهادات (1)')
        ->assertSee('الأب نفسه: تُدمج في علاقة السجل الباقي')->assertSee('تُكمَّل من السجل المدموج')->assertSee('حالة التوثيق')
        ->assertSee('خصوصية الحقول')->assertSee('مدراء العائلة فقط');
    expect(mergeState())->toBe($before);
});

test('a confirmed merge redirects every reference, keeps the duplicate as history and hides it everywhere', function () {
    $fixture = mergeFixture();
    $citations = Citation::query()->count();
    $assertions = Assertion::query()->count();

    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor'])->assertSessionHasNoErrors()->assertRedirect();

    [$survivor, $duplicate] = [$fixture['survivor']->fresh(), Person::query()->withoutGlobalScope('active')->find($fixture['duplicate']->id)];
    expect($duplicate->merged_into_id)->toBe($survivor->id)
        ->and(Person::query()->find($duplicate->id))->toBeNull()
        ->and($fixture['child']->outgoingRelationships()->sole()->related_person_id)->toBe($survivor->id)
        ->and($survivor->outgoingRelationships()->count())->toBe(1)
        ->and($fixture['branch']->fresh()->root_person_id)->toBe($survivor->id)
        ->and($survivor->death_year)->toBe(1870)->and($survivor->verification_status)->toBe('verified')
        ->and($survivor->field_visibility)->toBe(['birth_year' => 'restricted'])
        ->and($survivor->sources()->count())->toBe(1)->and($survivor->personAttributes()->count())->toBe(1)
        ->and(Citation::query()->count())->toBe($citations)->and(Citation::query()->where('field_key', 'father')->sole()->person_relationship_id)->toBe($survivor->outgoingRelationships()->sole()->id)
        ->and(Assertion::query()->count())->toBe($assertions)->and(Assertion::query()->sole()->person_id)->toBe($survivor->id)
        ->and(PersonMerge::query()->sole()->journal)->not->toBeEmpty()
        ->and(AuditLog::query()->where('action', 'merged')->where('auditable_type', PersonMerge::class)->sole()->reviewers_only)->toBeTrue();

    auth()->logout();
    $this->get(route('genealogy.person', ['merge', $duplicate->id]))->assertRedirect(route('genealogy.person', ['merge', $survivor->id, 'tree' => 'main']));
    expect(collect($this->getJson(route('genealogy.graph', ['merge', 'all' => 1]))->json('nodes'))->pluck('id'))->not->toContain($duplicate->id);
    $this->actingAs($fixture['reader'])->get(route('management.people.show', ['merge', $duplicate->id]))->assertRedirect(route('management.people.show', ['merge', $survivor->id, 'tree' => 'main']));
});

test('conflicting values need an explicit choice and the losing value is kept as a superseded claim', function () {
    $fixture = mergeFixture();
    $fixture['duplicate']->update(['full_name_ar' => 'محمد بن علي', 'first_name_ar' => 'محمد']);

    previewMerge($this, $fixture)->assertSee('اختر القيمة المعتمدة لحقل «الاسم الكامل»');
    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor'])->assertSessionHasErrors('merge');
    expect(PersonMerge::query()->count())->toBe(0);

    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor', 'full_name_ar' => 'merged'])->assertSessionHasNoErrors();
    $survivor = $fixture['survivor']->fresh();
    expect($survivor->full_name_ar)->toBe('محمد بن علي')->and($survivor->alternate_names)->toBe(['محمد'])
        ->and(Assertion::query()->where('fact_type', 'full_name')->where('state', 'superseded')->sole()->value)->toBe('محمد');
});

test('different fathers, ancestry cycles and an invalid root block the merge without changes', function () {
    $fixture = mergeFixture();
    $other = $fixture['tree']->people()->create(['first_name_ar' => 'عمر', 'full_name_ar' => 'عمر', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $before = mergeState();
    $service = app(PersonMergeService::class);

    $fixture['duplicate']->outgoingRelationships()->sole()->update(['related_person_id' => $other->id]);
    expect($service->plan($fixture['tree'], $fixture['survivor']->id, $fixture['duplicate']->id, ['verification_status' => 'survivor'])['blockers'])->toContain('لكل منهما أب مختلف في الشجرة المعتمدة. احسم الأبوة أولًا عبر الروايات والمراجعة؛ لا يُختار أب بالتخمين.');
    $fixture['duplicate']->outgoingRelationships()->sole()->update(['related_person_id' => $fixture['father']->id]);

    expect(implode(' ', $service->plan($fixture['tree'], $fixture['father']->id, $fixture['survivor']->id, ['verification_status' => 'survivor', 'birth_year' => 'survivor'])['blockers']))->toContain('أحدهما من أسلاف الآخر')
        ->and(implode(' ', $service->plan($fixture['tree'], $fixture['father']->id, $fixture['root']->id)['blockers']))->toContain('جذر الشجرة');
    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor'], str_repeat('0', 64))->assertSessionHasErrors();
    expect(mergeState())->toBe($before);
});

test('a merge may not publish a record that neither identity published', function () {
    $fixture = mergeFixture();
    $fixture['survivor']->update(['verification_status' => 'pending_review', 'is_living' => null]);
    $fixture['duplicate']->update(['verification_status' => 'verified', 'is_living' => null, 'death_year' => null]);
    $fixture['duplicate']->update(['is_living' => true]);
    $fixture['survivor']->update(['is_living' => false]);
    $fixture['survivor']->update(['status' => 'historical']);

    $plan = app(PersonMergeService::class)->plan($fixture['tree'], $fixture['survivor']->id, $fixture['duplicate']->id, ['verification_status' => 'merged', 'is_living' => 'survivor']);

    expect($plan['publication'])->toBe(['survivor' => false, 'merged' => false, 'result' => true])
        ->and(implode(' ', $plan['blockers']))->toContain('ستنشر للعامة سجلًا لم يكن أي منهما منشورًا');
});

test('a change after the preview is detected and nothing is merged', function () {
    $fixture = mergeFixture();
    $fingerprint = app(PersonMergeService::class)->plan($fixture['tree'], $fixture['survivor']->id, $fixture['duplicate']->id, ['verification_status' => 'survivor'])['fingerprint'];
    $fixture['duplicate']->update(['title' => 'تعديل متزامن']);
    $before = mergeState();

    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor'], $fingerprint)->assertSessionHasErrors('fingerprint');
    expect(mergeState())->toBe($before)->and(PersonMerge::query()->count())->toBe(0);
});

test('a failure during validation rolls back every change', function () {
    $fixture = mergeFixture();
    $before = mergeState();
    $this->mock(BranchIntegrityService::class)->shouldReceive('validate')->andThrow(new DomainException('فرع غير صالح'));

    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor'])->assertSessionHasErrors('merge');
    expect(mergeState())->toBe($before)->and(PersonMerge::query()->count())->toBe(0);
});

test('undo restores every touched row exactly and keeps later unrelated work', function () {
    $fixture = mergeFixture();
    $before = mergeState();
    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor'])->assertSessionHasNoErrors();
    $operation = PersonMerge::query()->sole();
    $later = $fixture['tree']->people()->create(['first_name_ar' => 'جديد', 'full_name_ar' => 'ابن أضيف بعد الدمج', 'status' => 'historical', 'verification_status' => 'pending_review']);
    $laterEdge = $fixture['tree']->personRelationships()->create(['person_id' => $later->id, 'related_person_id' => $fixture['survivor']->id, 'relationship_type' => 'father', 'is_primary' => true]);

    $this->actingAs($fixture['admin2'])->post(route('management.merges.undo', ['merge', $operation->id]), ['reason' => 'دمج خاطئ'])->assertSessionHasNoErrors();

    $laterEdge->delete();
    $later->delete();
    expect(mergeState())->toBe($before)
        ->and($operation->fresh()->status)->toBe('undone')->and($operation->fresh()->undo_reason)->toBe('دمج خاطئ')
        ->and(AuditLog::query()->where('action', 'merge_undone')->count())->toBe(3);
});

test('undo is refused when a touched row changed after the merge', function () {
    $fixture = mergeFixture();
    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor'])->assertSessionHasNoErrors();
    $operation = PersonMerge::query()->sole();
    $fixture['survivor']->fresh()->update(['death_year' => 1871]);
    $after = mergeState();

    $this->actingAs($fixture['admin'])->get(route('management.merges.show', ['merge', $operation->id]))->assertOk()->assertSee('التراجع الآلي غير آمن')->assertSee('death_year');
    $this->post(route('management.merges.undo', ['merge', $operation->id]), ['reason' => 'محاولة'])->assertSessionHasErrors('undo');
    expect(mergeState())->toBe($after)->and($operation->fresh()->status)->toBe('applied');
});

test('readers, editors and other families cannot merge or undo', function (string $role) {
    $fixture = mergeFixture();
    $fixture['outsider'] = mergeFixture('other')['admin'];
    executeMerge($this, $fixture, $role, ['verification_status' => 'survivor'])->assertForbidden();
    expect(PersonMerge::query()->count())->toBe(0);
    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor']);
    $this->actingAs($fixture[$role])->post(route('management.merges.undo', ['merge', PersonMerge::query()->sole()->id]), ['reason' => 'x'])->assertForbidden();
})->with(['reader', 'editor', 'outsider']);

test('a merge touching a cross-family link is reserved for the super admin', function () {
    $fixture = mergeFixture();
    $partner = mergeFixture('partner');
    CrossFamilyLink::query()->create([
        'left_person_id' => $fixture['duplicate']->id, 'right_person_id' => $partner['survivor']->id,
        'left_family_id' => $fixture['family']->id, 'right_family_id' => $partner['family']->id, 'kind' => 'same_person', 'status' => 'pending_review', 'claim' => 'تطابق',
    ]);

    previewMerge($this, $fixture)->assertSee('ينفذ الدمج مشرف المنصة فقط')->assertSee('عائلة partner');
    executeMerge($this, $fixture, 'admin', ['verification_status' => 'survivor'])->assertSessionHasErrors('merge');
    executeMerge($this, $fixture, 'super', ['verification_status' => 'survivor'])->assertSessionHasNoErrors();
    expect(CrossFamilyLink::query()->sole()->left_person_id)->toBe($fixture['survivor']->id);
});

test('pending proposals about the duplicate block the merge until decided', function () {
    $fixture = mergeFixture();
    $this->actingAs($fixture['reader'])->post(route('management.changes.store', 'merge'), ['kind' => 'person', 'operation' => 'update', 'target_id' => $fixture['duplicate']->id, 'reason' => 'تصحيح', 'title' => 'لقب'])->assertSessionHasNoErrors();

    expect(implode(' ', app(PersonMergeService::class)->plan($fixture['tree'], $fixture['survivor']->id, $fixture['duplicate']->id, ['verification_status' => 'survivor'])['blockers']))->toContain('مقترحات معلقة');
});
