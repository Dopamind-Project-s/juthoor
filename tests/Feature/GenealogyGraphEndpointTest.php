<?php

use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the progressive public graph enforces privacy and family boundaries', function () {
    $first = publicGenealogyExperience('graph-first');
    $second = publicGenealogyExperience('graph-second');
    $living = Person::query()->create([
        'family_tree_id' => $first['tree']->id,
        'canonical_key' => 'living',
        'first_name_ar' => 'حي',
        'full_name_ar' => 'شخص حي خاص',
        'status' => Person::STATUS_ACTIVE,
        'is_living' => true,
        'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
    ]);

    $this->getJson(route('genealogy.graph', $first['family']->slug))
        ->assertOk()
        ->assertJsonMissing(['id' => $living->id])
        ->assertJsonPath('meta.returned', 3);

    $this->getJson(route('genealogy.graph', [
        $first['family']->slug, 'root' => $second['root']->id,
    ]))->assertNotFound();
});

test('private graph and remote lookups require a family membership', function () {
    $graph = publicGenealogyExperience('private-graph');
    $reader = User::factory()->create();
    $outsider = User::factory()->create();
    FamilyMembership::query()->create([
        'family_id' => $graph['family']->id,
        'user_id' => $reader->id,
        'role' => FamilyMembership::ROLE_READER,
    ]);
    $living = Person::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'canonical_key' => 'living',
        'first_name_ar' => 'حي',
        'full_name_ar' => 'شخص حي',
        'status' => Person::STATUS_ACTIVE,
        'is_living' => true,
        'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
    ]);
    PersonRelationship::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'person_id' => $living->id,
        'related_person_id' => $graph['child']->id,
        'relationship_type' => PersonRelationship::TYPE_FATHER,
        'is_primary' => true,
        'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
    ]);

    $this->actingAs($reader)->getJson(route('genealogy.graph', [
        $graph['family']->slug, 'private' => 1,
    ]))->assertOk()->assertJsonFragment(['name' => 'شخص حي']);

    $this->actingAs($reader)->getJson(route('management.lookups.people', [
        $graph['family']->slug, 'q' => 'شخص',
    ]))->assertOk()->assertJsonFragment(['text' => 'شخص حي']);

    $this->actingAs($outsider)->getJson(route('genealogy.graph', [
        $graph['family']->slug, 'private' => 1,
    ]))->assertForbidden();
    $this->actingAs($outsider)->getJson(route('management.lookups.people', $graph['family']->slug))
        ->assertForbidden();
});

test('branch focus preserves the ancestor path and isolated mode excludes side branches', function () {
    $graph = publicGenealogyExperience('branch-graph');
    $sibling = Person::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'canonical_key' => 'side',
        'first_name_ar' => 'جانبي',
        'full_name_ar' => 'فرع جانبي',
        'status' => Person::STATUS_HISTORICAL,
        'is_living' => false,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
    PersonRelationship::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'person_id' => $sibling->id,
        'related_person_id' => $graph['root']->id,
        'relationship_type' => PersonRelationship::TYPE_FATHER,
        'is_primary' => true,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);

    $focus = $this->getJson(route('genealogy.graph', [
        $graph['family']->slug, 'branch' => $graph['branch']->slug, 'mode' => 'focus',
    ]))->assertOk()->json('nodes');
    expect(collect($focus)->firstWhere('id', $sibling->id)['status'])->toBe('dimmed');

    $this->getJson(route('genealogy.graph', [
        $graph['family']->slug, 'branch' => $graph['branch']->slug, 'mode' => 'isolated',
    ]))->assertOk()->assertJsonMissing(['id' => $sibling->id]);
});

test('Arabic public search is normalized and never returns private records', function () {
    $graph = publicGenealogyExperience('arabic-graph');
    $graph['child']->update(['full_name_ar' => 'إِبْرَاهِـيم الزعبي']);
    Person::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'canonical_key' => 'private-search',
        'first_name_ar' => 'إبراهيم',
        'full_name_ar' => 'إبراهيم الخاص',
        'status' => Person::STATUS_ACTIVE,
        'is_living' => true,
        'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
    ]);

    $this->getJson(route('genealogy.search', [
        $graph['family']->slug, 'q' => 'ابراهيم',
    ]))->assertOk()
        ->assertJsonFragment(['text' => 'إِبْرَاهِـيم الزعبي'])
        ->assertJsonMissing(['text' => 'إبراهيم الخاص']);
});

test('a dense progressive graph remains bounded in queries and response size', function () {
    $graph = publicGenealogyExperience('dense-graph');
    $parents = [$graph['root']];
    foreach (range(1, 4) as $generation) {
        $next = [];
        foreach ($parents as $parent) {
            foreach (range(1, 3) as $index) {
                $person = Person::query()->create([
                    'family_tree_id' => $graph['tree']->id,
                    'canonical_key' => "g{$generation}-{$parent->id}-{$index}",
                    'first_name_ar' => 'شخص',
                    'full_name_ar' => "شخص {$generation}-{$parent->id}-{$index}",
                    'status' => Person::STATUS_HISTORICAL,
                    'is_living' => false,
                    'is_living' => false,
                    'verification_status' => Source::VERIFICATION_VERIFIED,
                ]);
                PersonRelationship::query()->create([
                    'family_tree_id' => $graph['tree']->id,
                    'person_id' => $person->id,
                    'related_person_id' => $parent->id,
                    'relationship_type' => PersonRelationship::TYPE_FATHER,
                    'is_primary' => true,
                    'verification_status' => Source::VERIFICATION_VERIFIED,
                ]);
                $next[] = $person;
            }
        }
        $parents = $next;
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->getJson(route('genealogy.graph', [
        $graph['family']->slug, 'depth' => 5, 'limit' => 100,
    ]))->assertOk()->assertJsonPath('meta.returned', 100)->assertJsonPath('meta.truncated', true);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(16)
        ->and(strlen($response->getContent()))->toBeLessThan(100_000);
});
