<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Citation;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use App\Services\ChangeReviewService;
use App\Services\Genealogy\BranchFocusService;
use App\Services\Genealogy\GenealogyTraversalService;
use App\Services\Imports\AlQawasmehBranchImporter;
use Database\Seeders\AlQawasmehBranchSeeder;
use Database\Seeders\AlZoubiSharedAncestrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(AlZoubiSharedAncestrySeeder::class)->run();
});

function qawasmehCounts(): array
{
    return collect([Person::class, PersonRelationship::class, Branch::class, Source::class, Citation::class, AuditLog::class])->map(fn ($model) => $model::query()->count())->all();
}

test('Qawasmeh import reuses the shared anchors and imports every declared edge with pending evidence', function () {
    $tree = FamilyTree::query()->sole();
    $root = $tree->root_person_id;
    $anchors = $tree->people()->whereIn('canonical_key', ['ROOT-077', 'ROOT-078', 'ROOT-079'])->get()->keyBy('canonical_key');
    app(AlQawasmehBranchSeeder::class)->run();
    $manifest = app(AlQawasmehBranchImporter::class)->manifest();
    expect($tree->fresh()->root_person_id)->toBe($root)->and(FamilyTree::query()->count())->toBe(1)
        ->and($tree->people()->count())->toBe(79 + count($manifest['people']));
    foreach ($manifest['people'] as $row) {
        $person = $tree->people()->where('canonical_key', $row['key'])->sole();
        $father = $tree->people()->where('canonical_key', $row['father'])->sole();
        $edge = $person->outgoingRelationships()->sole();
        expect($person->full_name_ar)->toBe($row['name'])->and($person->verification_status)->toBe('pending_review')
            ->and($edge->related_person_id)->toBe($father->id)->and($edge->verification_status)->toBe('pending_review')
            ->and($edge->citations()->count())->toBe(1)->and($person->citations()->where('field_key', 'person')->count())->toBe(1);
    }
    $branch = Branch::query()->sole();
    expect($branch->rootPerson->canonical_key)->toBe('QW-0003')->and($branch->is_active)->toBeFalse()
        ->and($branch->verification_status)->toBe('pending_review')->and($branch->branch_type)->toBe('family_group')
        ->and($branch->rootPerson->personAttributes()->where('key', 'branch-founder')->count())->toBe(1);
    expect(Person::query()->where('canonical_key', 'QW-0031')->sole()->title)->toBe('مؤسس النظام')
        ->and(User::query()->count())->toBe(0);
    expect($anchors['ROOT-078']->fresh()->id)->toBe($anchors['ROOT-078']->id)
        ->and($anchors['ROOT-079']->fresh()->outgoingRelationships()->sole()->related_person_id)->toBe($anchors['ROOT-078']->id);
    $path = app(GenealogyTraversalService::class)->getAncestorPath($branch->root_person_id, null, false)->take(-6)->pluck('canonical_key')->all();
    expect($path)->toBe(['ROOT-076', 'ROOT-077', 'ROOT-078', 'QW-0001', 'QW-0002', 'QW-0003']);
});

test('Qawasmeh reruns preserve manual names titles approvals citations and audit counts', function () {
    $importer = app(AlQawasmehBranchImporter::class);
    $importer->import();
    $person = Person::query()->where('canonical_key', 'QW-0031')->sole();
    $person->update(['full_name_ar' => 'مصعب — اسم راجعه المستخدم', 'title' => 'لقب يدوي', 'verification_status' => 'verified']);
    $person->outgoingRelationships()->sole()->update(['verification_status' => 'sourced']);
    $counts = qawasmehCounts();
    $result = $importer->import();
    expect(qawasmehCounts())->toBe($counts)->and($result['people_created'])->toBe(0)->and($result['relationships_created'])->toBe(0)
        ->and($person->fresh()->title)->toBe('لقب يدوي')->and($person->fresh()->full_name_ar)->toBe('مصعب — اسم راجعه المستخدم')
        ->and($person->fresh()->verification_status)->toBe('verified');
});

test('a late attachment stable identity conflict rolls back the whole import', function () {
    $tree = FamilyTree::query()->sole();
    $last = collect(app(AlQawasmehBranchImporter::class)->manifest()['people'])->last();
    $tree->people()->create(['canonical_key' => $last['key'], 'first_name_ar' => 'تعارض', 'full_name_ar' => 'تعارض']);
    $before = qawasmehCounts();
    expect(fn () => app(AlQawasmehBranchImporter::class)->import())->toThrow(DomainException::class, 'identity conflict');
    expect(qawasmehCounts())->toBe($before);
});

test('same name under another father remains distinct while an unkeyed existing sibling blocks import', function () {
    $tree = FamilyTree::query()->sole();
    $tree->people()->create(['full_name_ar' => 'زين العابدين', 'first_name_ar' => 'زين العابدين']);
    $importer = app(AlQawasmehBranchImporter::class);
    $importer->import();
    expect($tree->people()->where('full_name_ar', 'زين العابدين')->count())->toBeGreaterThan(1)
        ->and($tree->people()->where('full_name_ar', 'سليمان')->count())->toBeGreaterThan(1)
        ->and($tree->people()->where('full_name_ar', 'محمد')->count())->toBeGreaterThan(8);
    $child = $tree->people()->where('canonical_key', 'QW-0001')->sole();
    $child->update(['canonical_key' => null]);
    $before = qawasmehCounts();
    expect(fn () => $importer->import())->toThrow(DomainException::class);
    expect(qawasmehCounts())->toBe($before);
});

test('anchor parentage root source checksums and duplicate manifest keys block without changes', function () {
    $importer = app(AlQawasmehBranchImporter::class);
    $manifest = $importer->manifest();
    $manifest['sources'][0]['sha256'] = str_repeat('0', 64);
    $before = qawasmehCounts();
    expect(fn () => $importer->import($manifest))->toThrow(DomainException::class, 'changed attachment');
    expect(qawasmehCounts())->toBe($before);
    $manifest = $importer->manifest();
    $manifest['people'][] = $manifest['people'][0];
    expect(fn () => $importer->import($manifest))->toThrow(DomainException::class, 'duplicated positional');
    expect(qawasmehCounts())->toBe($before);
    $tree = FamilyTree::query()->sole();
    $anchor = $tree->people()->where('canonical_key', 'ROOT-078')->sole();
    $anchor->outgoingRelationships()->sole()->update(['related_person_id' => $tree->people()->where('canonical_key', 'ROOT-076')->sole()->id]);
    expect(fn () => $importer->import())->toThrow(DomainException::class, 'parentage conflict');
    $tree->update(['root_person_id' => $anchor->id]);
    expect(fn () => $importer->import())->toThrow(DomainException::class, 'root conflict');
});

test('source observations render safely for authorized admins and stay inside the selected family and tree', function () {
    app(AlQawasmehBranchSeeder::class)->run();
    $family = Family::query()->sole();
    $source = Source::query()->where('reference', 'qawasmeh:قواسمه.png')->sole();
    $admin = User::factory()->create();
    $family->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
    $this->actingAs($admin)->get(route('management.sources.show', [$family->slug, $source->id]))->assertOk()->assertSee('سجل مصدر ينتظر حسم الهوية والأبوة');
    $this->get(route('management.sections.index', [$family->slug, 'branches']))->assertOk()->assertSee('القواسمة');
    $otherTree = $family->familyTrees()->create(['name_ar' => 'اختبار', 'slug' => 'other', 'is_active' => true]);
    $this->get(route('management.sources.show', [$family->slug, $source->id, 'tree' => $otherTree->slug]))->assertOk()->assertDontSee('سجل مصدر ينتظر حسم الهوية والأبوة');
    $this->actingAs(User::factory()->create())->get(route('management.sources.show', [$family->slug, $source->id]))->assertForbidden();
    expect(Citation::query()->where('field_key', 'source_observation')->where('verification_status', 'unresolved')->count())->toBeGreaterThan(300);
});

test('pending people remain private until reviewed and founder focus uses the approved shared path', function () {
    app(AlQawasmehBranchSeeder::class)->run();
    $tree = FamilyTree::query()->sole();
    $branch = Branch::query()->sole();
    expect(Person::query()->treeVisible()->where('canonical_key', 'like', 'QW-%')->count())->toBe(0)
        ->and(Person::query()->publiclyVisible()->where('canonical_key', 'like', 'QW-%')->count())->toBe(0);
    $admin = User::factory()->create();
    $submitter = User::factory()->create();
    foreach ([$admin, $submitter] as $user) {
        $tree->family->memberships()->create(['user_id' => $user->id, 'role' => 'admin']);
    }
    $founder = $branch->rootPerson;
    $reviews = app(ChangeReviewService::class);
    $change = $reviews->submit($tree, $submitter, 'person', 'update', $founder->id, ['verification_status' => 'verified', 'status' => 'historical'], 'مراجعة المصدر');
    $reviews->decide($tree, $change, $admin, 'accepted', 'تحقق مستقل');
    expect($founder->fresh()->verification_status)->toBe('verified')->and(Person::query()->publiclyVisible()->whereKey($founder->id)->exists())->toBeTrue();
    // Reviewer approvals of the path are required for normal branch traversal.
    $tree->personRelationships()->update(['verification_status' => 'verified']);
    $focus = app(BranchFocusService::class)->getBranchFocus($branch->id, 'isolated');
    $keys = collect($focus['nodes'])->pluck('person.canonical_key')->all();
    expect($keys)->toContain('ROOT-001', 'ROOT-077', 'ROOT-078', 'QW-0002', 'QW-0003', 'QW-0031')
        ->and($keys)->not->toContain('ROOT-079');
    $rejected = $reviews->submit($tree, $submitter, 'relationship', 'update', $founder->outgoingRelationships()->sole()->id, ['verification_status' => 'sourced'], 'اقتراح علاقة');
    $reviews->decide($tree, $rejected, $admin, 'rejected', 'المصدر مختلف');
    expect($rejected->fresh()->status)->toBe('rejected');
});
