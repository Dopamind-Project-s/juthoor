<?php

use App\Models\Branch;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{family: Family, tree: FamilyTree, root: Person, founder: Person, child: Person, branch: Branch} */
function publicGenealogyExperience(string $familySlug = 'example-family'): array
{
    $family = Family::query()->create([
        'name_ar' => 'عائلة المثال',
        'slug' => $familySlug,
        'is_active' => true,
    ]);
    $tree = FamilyTree::query()->create([
        'family_id' => $family->id,
        'name_ar' => 'الشجرة الأساسية',
        'slug' => 'main',
        'is_primary' => true,
        'is_active' => true,
    ]);
    $root = Person::query()->create([
        'family_tree_id' => $tree->id,
        'canonical_key' => 'root',
        'first_name_ar' => 'الجد',
        'full_name_ar' => 'الجد المؤسس',
        'status' => Person::STATUS_HISTORICAL,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
    $founder = Person::query()->create([
        'family_tree_id' => $tree->id,
        'canonical_key' => 'founder',
        'first_name_ar' => 'مؤسس',
        'full_name_ar' => 'مؤسس الفرع',
        'status' => Person::STATUS_HISTORICAL,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
    $child = Person::query()->create([
        'family_tree_id' => $tree->id,
        'canonical_key' => 'child',
        'first_name_ar' => 'ابن',
        'full_name_ar' => 'ابن المؤسس',
        'status' => Person::STATUS_HISTORICAL,
        'verification_status' => Source::VERIFICATION_SOURCED,
    ]);

    foreach ([[$founder, $root], [$child, $founder]] as [$person, $father]) {
        PersonRelationship::query()->create([
            'family_tree_id' => $tree->id,
            'person_id' => $person->id,
            'related_person_id' => $father->id,
            'relationship_type' => PersonRelationship::TYPE_FATHER,
            'is_primary' => true,
            'verification_status' => Source::VERIFICATION_VERIFIED,
        ]);
    }

    $tree->update(['root_person_id' => $root->id]);
    $branch = Branch::query()->create([
        'family_tree_id' => $tree->id,
        'root_person_id' => $founder->id,
        'branch_type' => Branch::TYPE_FAMILY_GROUP,
        'name_ar' => 'فرع المثال',
        'slug' => 'example-branch',
        'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS,
        'is_active' => true,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);

    return compact('family', 'tree', 'root', 'founder', 'child', 'branch');
}

test('a public family tree renders the interactive shell backed by its graph endpoint', function () {
    $graph = publicGenealogyExperience();

    $response = $this->get(route('genealogy.tree', $graph['family']->slug));

    $response->assertOk()
        ->assertSee('data-tree-app', false)
        ->assertSee(route('genealogy.graph', $graph['family']->slug), false)
        ->assertSee('genealogy-tree.js');

    $this->getJson(route('genealogy.graph', $graph['family']->slug))
        ->assertOk()
        ->assertJsonPath('root_id', $graph['root']->id)
        ->assertJsonCount(3, 'nodes')
        ->assertJsonCount(2, 'edges');
});

test('selecting a branch applies focus states to its real genealogy nodes', function () {
    $graph = publicGenealogyExperience();

    $response = $this->get(route('genealogy.branch', [$graph['family']->slug, $graph['branch']->slug]));

    $response->assertOk()
        ->assertSee('فرع فرع المثال')
        ->assertSee('data-branch="example-branch"', false);

    $nodes = $this->getJson(route('genealogy.graph', [
        $graph['family']->slug, 'branch' => $graph['branch']->slug,
    ]))->assertOk()->json('nodes');

    expect(collect($nodes)->pluck('status', 'id'))
        ->toMatchArray([
            $graph['root']->id => 'ancestor_path',
            $graph['founder']->id => 'branch_root',
            $graph['child']->id => 'branch_descendant',
        ]);
});

test('a public person page renders the ancestor path and descendants', function () {
    $graph = publicGenealogyExperience();

    $response = $this->get(route('genealogy.person', [$graph['family']->slug, $graph['founder']->id]));

    $response->assertOk()
        ->assertSeeInOrder(['الجد المؤسس', 'مؤسس الفرع'])
        ->assertSee('ابن المؤسس')
        ->assertSee('تركيز على فرع فرع المثال');
});

test('living and unreviewed people are not exposed by public genealogy routes', function () {
    $graph = publicGenealogyExperience();
    $privatePerson = Person::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'canonical_key' => 'private',
        'first_name_ar' => 'خاص',
        'full_name_ar' => 'شخص حي خاص',
        'status' => Person::STATUS_ACTIVE,
        'is_living' => true,
        'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
    ]);

    $this->getJson(route('genealogy.graph', $graph['family']->slug))
        ->assertOk()->assertJsonMissing(['name' => 'شخص حي خاص']);
    $this->get(route('genealogy.person', [$graph['family']->slug, $privatePerson->id]))->assertNotFound();
});

test('a person from another family is hidden instead of confirming their existence', function () {
    $firstGraph = publicGenealogyExperience('first-family');
    $secondGraph = publicGenealogyExperience('second-family');

    $this->get(route('genealogy.person', [$firstGraph['family']->slug, $secondGraph['root']->id]))
        ->assertNotFound();
});

test('public genealogy views escape names stored in the database', function () {
    $graph = publicGenealogyExperience();
    $graph['root']->update(['full_name_ar' => '<script>alert("unsafe")</script>']);

    $response = $this->get(route('genealogy.person', [$graph['family']->slug, $graph['root']->id]));

    $response->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>alert("unsafe")</script>', false);
});
