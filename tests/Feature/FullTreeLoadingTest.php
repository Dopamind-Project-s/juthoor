<?php

use App\Models\Citation;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Imports\LegacySeeders\AlZoubiResearchContextSeeder;
use App\Services\Imports\LegacySeeders\AlZoubiSharedAncestrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('all supplied ancestry is available automatically without upgrading names keys or statuses', function () {
    $this->seed(AlZoubiSharedAncestrySeeder::class);
    $before = Person::query()->get()->toJson();
    $edgesBefore = PersonRelationship::query()->get()->toJson();
    $nodes = [];
    $edges = [];
    $cursor = 0;
    do {
        $response = $this->getJson(route('genealogy.graph', ['al-zoubi', 'all' => 1, 'limit' => 10, 'cursor' => $cursor]))->assertOk();
        $nodes = array_merge($nodes, $response->json('nodes'));
        $edges = array_merge($edges, $response->json('edges'));
        $cursor = $response->json('meta.next_cursor');
    } while (! $response->json('meta.complete'));
    expect($nodes)->toHaveCount(79)->and($edges)->toHaveCount(78)->and(array_unique(array_column($nodes, 'id')))->toHaveCount(79);
    expect(array_unique(array_column($nodes, 'verification')))->toBe(['pending_review']);
    $ali = Person::query()->where('canonical_key', 'ROOT-049')->sole();
    $aliNode = collect($nodes)->firstWhere('id', $ali->id);
    expect($aliNode['attributes'])->toHaveCount(2);
    $this->get(route('genealogy.person', ['al-zoubi', $ali->id]))->assertOk()->assertSee('قيد المراجعة')->assertSee('صحابي');
    $this->get(route('genealogy.tree', 'al-zoubi'))->assertOk()->assertSee('data-tree-progress', false)->assertSee('data-attribute-legend', false);
    $this->seed(AlZoubiResearchContextSeeder::class);
    $this->seed(AlZoubiResearchContextSeeder::class);
    expect(Person::query()->get()->toJson())->toBe($before)->and(PersonRelationship::query()->get()->toJson())->toBe($edgesBefore);
    expect(Citation::query()->where('field_key', 'research_context')->count())->toBe(6);
});

test('batch loading excludes living restricted pending and inactive attributes while private mode stays authorized', function () {
    $this->seed(AlZoubiSharedAncestrySeeder::class);
    $family = Family::query()->sole();
    $tree = $family->primaryTree;
    $root = $tree->rootPerson;
    $root->update(['is_living' => true]);
    $tree->people()->create(['first_name_ar' => 'مخفي', 'full_name_ar' => 'شخص خاص', 'status' => 'active', 'verification_status' => 'verified']);
    $tree->people()->create(['first_name_ar' => 'معلق', 'full_name_ar' => 'طلب معلق', 'status' => 'historical', 'verification_status' => 'pending_review']);
    PersonAttribute::query()->where('key', 'companion')->update(['is_active' => false]);
    $response = $this->getJson(route('genealogy.graph', ['al-zoubi', 'all' => 1]))->assertOk()->assertJsonPath('root_id', null);
    expect(collect($response->json('nodes'))->pluck('id'))->not->toContain($root->id);
    expect(collect($response->json('edges'))->pluck('parent_id'))->not->toContain($root->id);
    $response->assertDontSee('شخص خاص')->assertDontSee('طلب معلق')->assertDontSee('صحابي');
    $this->getJson(route('genealogy.graph', ['al-zoubi', 'all' => 1, 'private' => 1]))->assertForbidden();
    $user = User::factory()->create();
    $family->memberships()->create(['user_id' => $user->id, 'role' => 'reader']);
    $this->actingAs($user)->getJson(route('genealogy.graph', ['al-zoubi', 'all' => 1, 'private' => 1]))->assertOk()->assertJsonCount(81, 'nodes');
});
