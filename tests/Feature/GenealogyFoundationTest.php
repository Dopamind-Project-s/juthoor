<?php

use App\Models\Branch;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Services\Genealogy\BranchFocusService;
use App\Services\Genealogy\BranchMembershipService;
use App\Services\Genealogy\GenealogyTraversalService;
use App\Services\Imports\LegacySeeders\AlZoubiGenealogySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function genealogyFamily(string $slug = 'test-family'): Family
{
    return Family::query()->create(['name_ar' => 'عائلة اختبار', 'slug' => $slug]);
}

function genealogyTree(Family $family, string $slug = 'main'): FamilyTree
{
    return FamilyTree::query()->create([
        'family_id' => $family->id,
        'name_ar' => 'شجرة اختبار',
        'slug' => $slug,
        'is_primary' => true,
    ]);
}

function genealogyPerson(FamilyTree $tree, string $key, string $name): Person
{
    return Person::query()->create([
        'family_tree_id' => $tree->id,
        'canonical_key' => $key,
        'first_name_ar' => $name,
        'full_name_ar' => $name,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
}

function genealogyFather(Person $child, Person $father): PersonRelationship
{
    return PersonRelationship::query()->create([
        'family_tree_id' => $child->family_tree_id,
        'person_id' => $child->id,
        'related_person_id' => $father->id,
        'relationship_type' => PersonRelationship::TYPE_FATHER,
        'is_primary' => true,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
}

/** @return array<string, Person|FamilyTree> */
function genealogyGraph(): array
{
    $tree = genealogyTree(genealogyFamily());
    $adam = genealogyPerson($tree, 'adam', 'آدم');
    $mahmoud = genealogyPerson($tree, 'mahmoud', 'محمود');
    $mohammad = genealogyPerson($tree, 'mohammad', 'محمد');
    $ahmad = genealogyPerson($tree, 'ahmad', 'أحمد');
    $musab = genealogyPerson($tree, 'musab', 'مصعب');
    $mufid = genealogyPerson($tree, 'mufid', 'مفيد');
    $khaled = genealogyPerson($tree, 'khaled', 'خالد');

    genealogyFather($mahmoud, $adam);
    genealogyFather($mohammad, $mahmoud);
    genealogyFather($ahmad, $mohammad);
    genealogyFather($musab, $mohammad);
    genealogyFather($mufid, $mohammad);
    genealogyFather($khaled, $musab);
    $tree->update(['root_person_id' => $adam->id]);

    return compact('tree', 'adam', 'mahmoud', 'mohammad', 'ahmad', 'musab', 'mufid', 'khaled');
}

function genealogyBranch(FamilyTree $tree, Person $root, string $slug, ?Branch $parent = null): Branch
{
    return Branch::query()->create([
        'family_tree_id' => $tree->id,
        'parent_branch_id' => $parent?->id,
        'root_person_id' => $root->id,
        'branch_type' => Branch::TYPE_FAMILY_GROUP,
        'name_ar' => $root->full_name_ar,
        'slug' => $slug,
        'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS,
        'is_active' => true,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
}

test('a family exposes only its current primary tree', function () {
    $family = genealogyFamily();
    $first = genealogyTree($family);
    $second = genealogyTree($family, 'second');

    expect($first->refresh()->is_primary)->toBeFalse()
        ->and($family->fresh()->primaryTree->is($second))->toBeTrue();
});

test('duplicate person names are allowed', function () {
    $tree = genealogyTree(genealogyFamily());
    genealogyPerson($tree, 'duplicate-1', 'محمد');
    genealogyPerson($tree, 'duplicate-2', 'محمد');

    expect($tree->people()->where('full_name_ar', 'محمد')->count())->toBe(2);
});

test('canonical keys are unique within a tree but reusable across trees', function () {
    $family = genealogyFamily();
    $firstTree = genealogyTree($family);
    $secondTree = genealogyTree($family, 'second');
    genealogyPerson($firstTree, 'shared-key', 'محمد');
    genealogyPerson($secondTree, 'shared-key', 'محمد');

    expect(fn () => genealogyPerson($firstTree, 'shared-key', 'أحمد'))
        ->toThrow(QueryException::class);
});

test('father relationships are graph edges and identical edges are rejected', function () {
    $tree = genealogyTree(genealogyFamily());
    $father = genealogyPerson($tree, 'father', 'محمود');
    $child = genealogyPerson($tree, 'child', 'أحمد');
    $relationship = genealogyFather($child, $father);

    expect($relationship->relatedPerson->is($father))->toBeTrue()
        ->and(fn () => genealogyFather($child, $father))->toThrow(DomainException::class);
});

test('a person cannot have two primary fathers', function () {
    $tree = genealogyTree(genealogyFamily());
    $firstFather = genealogyPerson($tree, 'father-1', 'الأب الأول');
    $secondFather = genealogyPerson($tree, 'father-2', 'الأب الثاني');
    $child = genealogyPerson($tree, 'child', 'الابن');
    genealogyFather($child, $firstFather);

    expect(fn () => genealogyFather($child, $secondFather))->toThrow(DomainException::class);
});

test('the database rejects an identical relationship when model validation is bypassed', function () {
    $tree = genealogyTree(genealogyFamily());
    $father = genealogyPerson($tree, 'father', 'الأب');
    $child = genealogyPerson($tree, 'child', 'الابن');
    $relationship = genealogyFather($child, $father);

    expect(fn () => DB::table('person_relationships')->insert([
        'family_tree_id' => $tree->id,
        'person_id' => $child->id,
        'related_person_id' => $father->id,
        'relationship_type' => PersonRelationship::TYPE_FATHER,
        'is_primary' => true,
        'verification_status' => Source::VERIFICATION_VERIFIED,
        'created_at' => $relationship->created_at,
        'updated_at' => $relationship->updated_at,
    ]))->toThrow(QueryException::class);
});

test('a confirmed tree root cannot have a verified primary father', function () {
    $tree = genealogyTree(genealogyFamily());
    $root = genealogyPerson($tree, 'root', 'الجذر');
    $candidateFather = genealogyPerson($tree, 'candidate-father', 'أب مرشح');
    $tree->update(['root_person_id' => $root->id]);

    expect(fn () => genealogyFather($root, $candidateFather))->toThrow(DomainException::class);
});

test('self and cross-tree relationships are rejected', function () {
    $firstTree = genealogyTree(genealogyFamily());
    $secondTree = genealogyTree(genealogyFamily('other-family'));
    $first = genealogyPerson($firstTree, 'first', 'الأول');
    $second = genealogyPerson($secondTree, 'second', 'الثاني');

    expect(fn () => genealogyFather($first, $first))->toThrow(DomainException::class)
        ->and(fn () => genealogyFather($first, $second))->toThrow(DomainException::class);
});

test('genealogy cycles are rejected', function () {
    $tree = genealogyTree(genealogyFamily());
    $father = genealogyPerson($tree, 'father', 'الأب');
    $child = genealogyPerson($tree, 'child', 'الابن');
    genealogyFather($child, $father);

    expect(fn () => genealogyFather($father, $child))->toThrow(DomainException::class);
});

test('ancestor paths and descendant subtrees use verified relationships', function () {
    $graph = genealogyGraph();
    $traversal = app(GenealogyTraversalService::class);

    expect($traversal->getAncestorPath($graph['musab']->id)->pluck('canonical_key')->all())
        ->toBe(['adam', 'mahmoud', 'mohammad', 'musab'])
        ->and($traversal->getDescendantIds($graph['musab']->id))
        ->toBe([$graph['musab']->id, $graph['khaled']->id]);
});

test('branches may start at different genealogy depths', function () {
    $graph = genealogyGraph();
    $first = genealogyBranch($graph['tree'], $graph['musab'], 'musab');
    $second = genealogyBranch($graph['tree'], $graph['khaled'], 'khaled', $first);

    expect($first->rootPerson->is($graph['musab']))->toBeTrue()
        ->and($second->rootPerson->is($graph['khaled']))->toBeTrue()
        ->and($second->parentBranch->is($first))->toBeTrue();
});

test('nested branch founders must descend from the parent founder', function () {
    $graph = genealogyGraph();
    $parent = genealogyBranch($graph['tree'], $graph['musab'], 'musab');

    expect(fn () => genealogyBranch($graph['tree'], $graph['mufid'], 'invalid-child', $parent))
        ->toThrow(DomainException::class);
});

test('branch hierarchy cycles are rejected', function () {
    $graph = genealogyGraph();
    $parent = genealogyBranch($graph['tree'], $graph['musab'], 'musab');
    $child = genealogyBranch($graph['tree'], $graph['khaled'], 'khaled', $parent);

    expect(fn () => $parent->update(['parent_branch_id' => $child->id]))
        ->toThrow(DomainException::class);
});

test('branch founders and parents must belong to the same tree', function () {
    $graph = genealogyGraph();
    $otherTree = genealogyTree(genealogyFamily('other-family'));
    $outsider = genealogyPerson($otherTree, 'outsider', 'غريب');
    $otherBranch = genealogyBranch($otherTree, $outsider, 'other');

    expect(fn () => genealogyBranch($graph['tree'], $outsider, 'wrong-root'))
        ->toThrow(DomainException::class)
        ->and(fn () => Branch::query()->create([
            'family_tree_id' => $graph['tree']->id,
            'parent_branch_id' => $otherBranch->id,
            'root_person_id' => $graph['musab']->id,
            'name_ar' => 'فرع',
            'slug' => 'wrong-parent',
            'is_active' => true,
        ]))->toThrow(DomainException::class);
});

test('active branches require a confirmed founder', function () {
    $tree = genealogyTree(genealogyFamily());

    expect(fn () => Branch::query()->create([
        'family_tree_id' => $tree->id,
        'name_ar' => 'فرع',
        'slug' => 'rootless',
        'is_active' => true,
    ]))->toThrow(DomainException::class);
});

test('branch membership is derived from descendants and overlaps nested branches', function () {
    $graph = genealogyGraph();
    $main = genealogyBranch($graph['tree'], $graph['musab'], 'musab');
    $nested = genealogyBranch($graph['tree'], $graph['khaled'], 'khaled', $main);
    $membership = app(BranchMembershipService::class);

    expect($membership->contains($main, $graph['khaled']))->toBeTrue()
        ->and($membership->contains($nested, $graph['khaled']))->toBeTrue()
        ->and($membership->contains($main, $graph['mufid']))->toBeFalse();
});

test('focus mode returns the ancestor path and dims sibling branches', function () {
    $graph = genealogyGraph();
    $branch = genealogyBranch($graph['tree'], $graph['musab'], 'musab');
    $result = app(BranchFocusService::class)->getBranchFocus($branch->id);
    $states = collect($result['nodes'])->mapWithKeys(
        fn (array $node): array => [$node['person']->canonical_key => $node['state']],
    );

    expect($states->get('adam'))->toBe('ancestor_path')
        ->and($states->get('musab'))->toBe('branch_root')
        ->and($states->get('khaled'))->toBe('branch_descendant')
        ->and($states->get('ahmad'))->toBe('dimmed')
        ->and($states->get('mufid'))->toBe('dimmed');
});

test('isolated mode excludes sibling branches', function () {
    $graph = genealogyGraph();
    $branch = genealogyBranch($graph['tree'], $graph['musab'], 'musab');
    $result = app(BranchFocusService::class)->getBranchFocus($branch->id, BranchFocusService::MODE_ISOLATED);
    $keys = collect($result['nodes'])->pluck('person.canonical_key');

    expect($keys)->toContain('adam', 'mahmoud', 'mohammad', 'musab', 'khaled')
        ->not->toContain('ahmad', 'mufid');
});

test('the Al-Zoubi seed is safe idempotent and preserves documented labels', function () {
    $seeder = app(AlZoubiGenealogySeeder::class);
    $firstImport = $seeder->import();
    $secondImport = $seeder->import();

    $family = Family::query()->where('slug', 'al-zoubi')->sole();
    $tree = $family->primaryTree;

    expect($tree->people)->toHaveCount(85)
        ->and($firstImport)->toMatchArray(['created' => 169, 'updated' => 0, 'skipped' => 1418, 'unresolved' => 10, 'conflicted' => 2])
        ->and($secondImport)->toMatchArray(['created' => 0, 'updated' => 169, 'skipped' => 1418, 'unresolved' => 10, 'conflicted' => 2])
        ->and($tree->rootPerson->canonical_key)->toBe('ROOT-001')
        ->and($tree->people()->where('canonical_key', 'ROOT-001')->count())->toBe(1)
        ->and($tree->personRelationships)->toHaveCount(84)
        ->and($tree->branches)->toHaveCount(6)
        ->and($tree->branches()->where('name_ar', 'العوتلات')->count())->toBe(1)
        ->and($tree->branches()->where('name_ar', 'العودات')->count())->toBe(0)
        ->and($tree->branches()->where('is_active', true)->count())->toBe(4)
        ->and($tree->branches()->where('verification_status', Source::VERIFICATION_PENDING_REVIEW)->count())->toBe(2)
        ->and($family->sources)->toHaveCount(1);
});

test('resolved branch founders follow reviewed paths and dependency order', function () {
    app(AlZoubiGenealogySeeder::class)->import();
    $tree = Family::query()->where('slug', 'al-zoubi')->sole()->primaryTree;
    $branches = $tree->branches->keyBy('slug');
    $traversal = app(GenealogyTraversalService::class);

    expect($branches['bakr']->rootPerson->canonical_key)->toBe('AZ-BAKR-FOUNDER')
        ->and($branches['eid']->parentBranch->is($branches['bakr']))->toBeTrue()
        ->and($branches['khamis']->parentBranch->is($branches['bakr']))->toBeTrue()
        ->and($traversal->getAncestorPath($branches['eid']->root_person_id)->pluck('canonical_key')->take(-5)->values()->all())
        ->toBe(['ROOT-077', 'AZ-BAKR-ALI', 'AZ-BAKR-MUHAMMAD', 'AZ-BAKR-FOUNDER', 'AZ-EID-FOUNDER'])
        ->and($traversal->getAncestorPath($branches['khamis']->root_person_id)->pluck('canonical_key')->take(-5)->values()->all())
        ->toBe(['ROOT-077', 'AZ-BAKR-ALI', 'AZ-BAKR-MUHAMMAD', 'AZ-BAKR-FOUNDER', 'AZ-KHAMIS-FOUNDER'])
        ->and($traversal->getAncestorPath($branches['al-rahaymeh']->root_person_id)->pluck('canonical_key')->take(-4)->values()->all())
        ->toBe(['ROOT-077', 'AZ-RAHAYMEH-SULAYMAN', 'AZ-RAHAYMEH-ABD-AL-QADIR', 'AZ-RAHAYMEH-FOUNDER'])
        ->and($branches['al-awtalat']->root_person_id)->toBeNull()
        ->and($branches['al-awtalat']->is_active)->toBeFalse()
        ->and($branches['al-qawasmeh']->root_person_id)->toBeNull()
        ->and($branches['al-qawasmeh']->is_active)->toBeFalse();
});
