<?php

use App\Models\Branch;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{family: Family, tree: FamilyTree, user: User} */
function searchableFamily(): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة البحث', 'slug' => 'search-family']);
    $tree = FamilyTree::query()->create(['family_id' => $family->id, 'name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $user = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $user->id, 'role' => 'reader']);

    return compact('family', 'tree', 'user');
}

function searchablePerson(FamilyTree $tree, string $key, string $name): Person
{
    return Person::query()->create([
        'family_tree_id' => $tree->id, 'canonical_key' => $key,
        'first_name_ar' => $name, 'full_name_ar' => $name,
        'status' => Person::STATUS_HISTORICAL, 'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
}

test('Arabic person search removes diacritics tatweel and normalizes alef forms without changing the stored name', function () {
    $context = searchableFamily();
    $person = searchablePerson($context['tree'], 'ibrahim', 'إِبْرَاهِـيم الزعبي');
    searchablePerson($context['tree'], 'other', 'محمود الزعبي');

    $response = $this->actingAs($context['user'])->get(route('management.families.show', [
        $context['family']->slug, 'q' => 'ابراهيم',
    ]));

    $response->assertOk()->assertSee('إِبْرَاهِـيم الزعبي')->assertDontSee('محمود الزعبي');
    expect($person->fresh()->full_name_ar)->toBe('إِبْرَاهِـيم الزعبي')
        ->and($person->name_search)->toBe('ابراهيم الزعبي');
});

test('branch and verification filters derive people from verified genealogy relationships', function () {
    $context = searchableFamily();
    $root = searchablePerson($context['tree'], 'root', 'مؤسس الفرع');
    $member = searchablePerson($context['tree'], 'member', 'عضو الفرع');
    $outside = searchablePerson($context['tree'], 'outside', 'خارج الفرع');
    PersonRelationship::query()->create([
        'family_tree_id' => $context['tree']->id, 'person_id' => $member->id, 'related_person_id' => $root->id,
        'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
    $branch = Branch::query()->create([
        'family_tree_id' => $context['tree']->id, 'root_person_id' => $root->id,
        'name_ar' => 'فرع الاختبار', 'slug' => 'test-branch', 'is_active' => true,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);

    $this->actingAs($context['user'])->get(route('management.families.show', [
        $context['family']->slug, 'branch' => $branch->slug, 'verification' => Source::VERIFICATION_VERIFIED,
    ]))->assertOk()->assertSee($root->full_name_ar)->assertSee($member->full_name_ar)->assertDontSee($outside->full_name_ar);
});

test('management collections are paginated from the database and preserve filters', function () {
    $context = searchableFamily();
    foreach (range(1, 22) as $number) {
        searchablePerson($context['tree'], 'person-'.$number, 'شخص '.$number);
    }

    $this->actingAs($context['user'])->get(route('management.families.show', [
        $context['family']->slug, 'q' => 'شخص', 'people_page' => 2,
    ]))->assertOk()->assertViewHas('people', fn ($people): bool => $people->currentPage() === 2
        && $people->perPage() === 15 && $people->total() === 22)
        ->assertSee('q=%D8%B4%D8%AE%D8%B5', false);
});
