<?php

use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Genealogy\BranchFocusService;
use Database\Seeders\Fixtures\TreeStressFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->family = Family::query()->create(['name_ar' => 'عشيرة الشين', 'slug' => 'sheen', 'is_active' => true]);
    $this->tree = $this->family->familyTrees()->create(['name_ar' => 'شجرة الشين', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $this->person = fn (string $name, ?Person $father = null, array $extra = [], string $edge = 'verified') => tap($this->tree->people()->create(['first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false, ...$extra]), function (Person $person) use ($father, $edge) {
        if ($father) {
            PersonRelationship::query()->create(['family_tree_id' => $this->tree->id, 'person_id' => $person->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => $edge]);
        }
    });
    // A long chain, a branching point with a founder, and hidden people of every kind.
    $chain = [$this->root = ($this->person)('الجد الجامع')];
    foreach (range(1, 8) as $i) {
        $chain[] = ($this->person)('سلف '.$i, end($chain), $i === 4 ? ['verification_status' => 'sourced'] : [], $i === 4 ? 'sourced' : 'verified');
    }
    $this->junction = end($chain);
    $this->founder = ($this->person)('مؤسس فخذ الشين', $this->junction);
    $this->other = ($this->person)('أخ المؤسس', $this->junction);
    $this->grandson = ($this->person)('ابن المؤسس', $this->founder);
    $this->living = ($this->person)('حفيد حي سري', $this->founder, ['is_living' => true]);
    $this->unknown = ($this->person)('مجهول الحالة سري', $this->founder, ['is_living' => null]);
    $this->pending = ($this->person)('قيد المراجعة سري', $this->other, ['verification_status' => 'pending_review'], 'pending_review');
    // A published person whose father is not public: shown, but never linked to the hidden father.
    $this->orphan = ($this->person)('ابن لأب غير منشور', $this->pending);
    $this->tree->update(['root_person_id' => $this->root->id]);
    $this->branch = Branch::query()->create(['family_tree_id' => $this->tree->id, 'name_ar' => 'فخذ الشين', 'slug' => 'sheen-fakhd', 'branch_type' => 'fakhd', 'root_person_id' => $this->founder->id, 'description' => 'وصف معتمد للفخذ', 'is_active' => true, 'verification_status' => 'verified']);
    $this->hiddenBranch = Branch::query()->create(['family_tree_id' => $this->tree->id, 'name_ar' => 'فرع غير منشور', 'slug' => 'hidden', 'root_person_id' => $this->other->id, 'description' => 'وصف سري', 'is_active' => false, 'verification_status' => 'pending_review']);
    $this->clan = CommunityEntity::query()->create(['family_id' => $this->family->id, 'entity_type' => 'clan', 'name_ar' => 'عشيرة', 'slug' => 'clan', 'publication_status' => 'published']);
    $this->entity = CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $this->clan->id, 'entity_type' => 'fakhd', 'name_ar' => 'صفحة فخذ الشين', 'slug' => 'sheen-page', 'publication_status' => 'published', 'branch_id' => $this->branch->id]);
    $this->full = function (array $query = []) {
        $nodes = $edges = $attributes = [];
        $cursor = 0;
        do {
            $response = $this->getJson(route('genealogy.graph', ['sheen', 'all' => 1, 'batch' => 10, 'cursor' => $cursor, ...$query]))->assertOk();
            $nodes = [...$nodes, ...$response->json('nodes')];
            $edges = [...$edges, ...$response->json('edges')];
            $meta = $response->json('meta');
            // Attribute dictionaries arrive per batch; the client merges them.
            $attributes = [...$attributes, ...$meta['attributes']];
            $raw[] = $response->getContent();
            $cursor = $meta['next_cursor'];
        } while (! $meta['complete']);

        return ['nodes' => collect($nodes)->keyBy('id'), 'edges' => collect($edges), 'meta' => ['attributes' => $attributes] + $meta, 'raw' => implode('', $raw)];
    };
});

test('every eligible person and approved father edge is present exactly once; hidden people appear nowhere in the payload', function () {
    $graph = ($this->full)();
    $eligible = Person::query()->where('family_tree_id', $this->tree->id)->publiclyVisible()->pluck('id')->sort()->values()->all();
    expect($graph['nodes']->keys()->sort()->values()->all())->toBe($eligible)
        ->and($graph['nodes']->count())->toBe(13);
    $expectedEdges = PersonRelationship::query()->where('family_tree_id', $this->tree->id)->whereIn('person_id', $eligible)->whereIn('related_person_id', $eligible)->publiclyVisible()->pluck('related_person_id', 'person_id')->all();
    expect($graph['edges']->mapWithKeys(fn ($edge) => [$edge['child_id'] => $edge['parent_id']])->sortKeys()->all())->toBe(collect($expectedEdges)->sortKeys()->all())
        ->and($graph['edges']->pluck('id')->unique()->count())->toBe($graph['edges']->count());
    // Edge verification travels with the edge so a sourced chain is never drawn as verified.
    expect($graph['edges']->firstWhere('child_id', ($this->tree->people()->where('full_name_ar', 'سلف 4')->value('id')))['verification'])->toBe('sourced');
    foreach (['حفيد حي سري', 'مجهول الحالة سري', 'قيد المراجعة سري', 'فرع غير منشور', 'وصف سري'] as $secret) {
        expect($graph['raw'])->not->toContain($secret);
    }
    expect($graph['nodes']->has($this->orphan->id))->toBeTrue()
        ->and($graph['edges']->contains(fn ($edge) => $edge['child_id'] === $this->orphan->id))->toBeFalse();
    foreach ([$this->living, $this->unknown, $this->pending] as $hidden) {
        expect($graph['edges']->contains(fn ($edge) => in_array($hidden->id, [$edge['child_id'], $edge['parent_id']], true)))->toBeFalse();
    }
    $this->getJson(route('genealogy.search', ['sheen', 'q' => 'سري']))->assertOk()->assertJsonCount(0, 'data');
    $this->get(route('genealogy.tree', 'sheen'))->assertOk()->assertDontSee('سري');
});

test('members see their family preview; another family and outsiders see nothing of it', function () {
    $member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $member->id, 'role' => 'reader']);
    $private = $this->actingAs($member)->getJson(route('genealogy.graph', ['sheen', 'all' => 1, 'batch' => 100, 'private' => 1]))->assertOk();
    expect(collect($private->json('nodes'))->pluck('name'))->toContain('قيد المراجعة سري');
    $outsider = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($outsider)->getJson(route('genealogy.graph', ['sheen', 'all' => 1, 'private' => 1]))->assertForbidden();
    $other = Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other', 'is_active' => true]);
    $other->familyTrees()->create(['name_ar' => 'ش', 'slug' => 'main', 'is_primary' => true, 'is_active' => true])->people()->create(['first_name_ar' => 'غريب', 'full_name_ar' => 'غريب', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    auth()->logout();
    expect(($this->full)()['raw'])->not->toContain('غريب');
    $this->getJson(route('genealogy.graph', ['other', 'all' => 1, 'branch' => 'sheen-fakhd']))->assertOk()->assertJsonMissing(['name' => 'مؤسس فخذ الشين']);
});

test('founders come only from approved associations: published branches (with public entity links) and the tree root', function () {
    $graph = ($this->full)();
    $founder = $graph['nodes'][$this->founder->id]['founder'];
    expect($founder['kind'])->toBe('fakhd')->and($founder['classification'])->toBe('مؤسس فخذ')->and($founder['branch'])->toBe('فخذ الشين')
        ->and($founder['description'])->toBe('وصف معتمد للفخذ')->and($founder['verification'])->toBe('verified')
        ->and($founder['entity_url'])->toBe(route('community.entities.show', ['sheen', 'sheen-page']))
        ->and($founder['branch_url'])->toContain('/tree/branches/sheen-fakhd');
    expect($graph['nodes'][$this->root->id]['founder']['kind'])->toBe('root')
        // An unpublished branch makes nobody a founder for visitors; names never make founders.
        ->and($graph['nodes'][$this->other->id])->not->toHaveKey('founder')
        ->and($graph['nodes'][$this->junction->id])->not->toHaveKey('founder');
    // An unpublished entity page is not linked.
    $this->entity->update(['publication_status' => 'draft']);
    expect(($this->full)()['nodes'][$this->founder->id]['founder'])->not->toHaveKey('entity_url');
    $this->branch->update(['is_active' => false]);
    expect(($this->full)()['nodes'][$this->founder->id])->not->toHaveKey('founder');
});

test('attribute badges come from the lookup table: labels and colours are dynamic, inactive or foreign attributes never shown', function () {
    $honour = PersonAttribute::query()->create(['key' => 'test-honour', 'label' => 'صفة شرفية', 'color' => '#7A1F3D', 'is_active' => true]);
    $inactive = PersonAttribute::query()->create(['key' => 'test-off', 'label' => 'صفة معطلة', 'color' => '#000000', 'is_active' => false]);
    $otherFamily = Family::query()->create(['name_ar' => 'ب', 'slug' => 'b', 'is_active' => true]);
    $foreign = PersonAttribute::query()->create(['family_id' => $otherFamily->id, 'key' => 'test-foreign', 'label' => 'صفة عائلة أخرى', 'color' => '#123456', 'is_active' => true]);
    $this->founder->personAttributes()->attach([$honour->id, $inactive->id, $foreign->id]);
    $graph = ($this->full)();
    expect($graph['nodes'][$this->founder->id]['attributes'])->toBe([$honour->id])
        ->and(collect($graph['meta']['attributes'])->firstWhere('id', $honour->id))->toMatchArray(['label' => 'صفة شرفية', 'color' => '#7A1F3D', 'key' => 'test-honour'])
        ->and($graph['raw'])->not->toContain('صفة معطلة')->not->toContain('صفة عائلة أخرى');
    $honour->update(['label' => 'صفة معدلة', 'color' => '#225588']);
    expect(collect(($this->full)()['meta']['attributes'])->firstWhere('id', $honour->id))->toMatchArray(['label' => 'صفة معدلة', 'color' => '#225588']);
    $honour->update(['is_active' => false]);
    expect(($this->full)()['nodes'][$this->founder->id])->not->toHaveKey('attributes');
});

test('the fakhd tree uses the BranchFocusService path and keeps every eligible ancestor', function () {
    $html = $this->get(route('genealogy.branch', ['sheen', 'sheen-fakhd']))->assertOk()->getContent();
    preg_match('/data-branch-path="([^"]*)"/', $html, $match);
    $path = json_decode(html_entity_decode($match[1]), true);
    $service = collect(app(BranchFocusService::class)->getBranchFocus($this->branch->id, 'isolated')['nodes'])->whereIn('state', ['ancestor_path', 'branch_root'])->map(fn ($node) => $node['person']->id)->sort()->values()->all();
    expect($path)->toBe($service)->and($path)->toHaveCount(10)->toContain($this->root->id, $this->junction->id, $this->founder->id);
    // The full tree page links back from the fakhd and offers the new controls.
    expect($html)->toContain('الشجرة الجامعة كاملة')->toContain('data-tree-action="fullscreen"')->toContain('data-view="ancestors"')->toContain('tree-layout.js');
    // The person page opens the tree on that person.
    $this->get(route('genealogy.person', ['sheen', $this->founder->id]))->assertOk()->assertSee('person='.$this->founder->id, false)->assertSee('view=ancestors', false);
    // An unpublished branch has no public fakhd tree.
    $this->get(route('genealogy.branch', ['sheen', 'hidden']))->assertNotFound();
});

test('full-tree batches are bounded and use a constant number of queries', function () {
    $this->getJson(route('genealogy.graph', ['sheen', 'all' => 1, 'batch' => 5000]))->assertUnprocessable();
    $this->getJson(route('genealogy.graph', ['sheen', 'all' => 1, 'batch' => 5]))->assertUnprocessable();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('genealogy.graph', ['sheen', 'all' => 1, 'batch' => 1000]))->assertOk();

        return count(DB::getQueryLog());
    };
    $before = $count();
    $father = $this->grandson;
    foreach (range(1, 40) as $i) {
        $father = ($this->person)('جيل '.$i, $i % 3 ? $father : $this->grandson);
    }
    Branch::query()->create(['family_tree_id' => $this->tree->id, 'name_ar' => 'فرع جديد', 'slug' => 'new', 'branch_type' => 'sub_fakhd', 'root_person_id' => $father->id, 'is_active' => true, 'verification_status' => 'verified']);
    expect($count())->toBe($before);
});

test('synthetic stress fixtures: opt-in, isolated, complete in the payload at scale, and labelled', function () {
    config(['seeding.demo_fixtures' => false]);
    expect(fn () => app(TreeStressFixtureSeeder::class)->run(200))->toThrow(RuntimeException::class);
    config(['seeding.demo_fixtures' => true]);
    app(TreeStressFixtureSeeder::class)->run(2000);
    app(TreeStressFixtureSeeder::class)->run(2000);
    $family = Family::query()->where('slug', 'demo-tree-stress-2000')->sole();
    expect($family->name_ar)->toContain('اصطناعية')->and(Person::query()->where('family_tree_id', $family->primaryTree->id)->count())->toBe(2000)
        ->and(Person::query()->where('family_tree_id', $this->tree->id)->count())->toBe(16);
    $eligible = Person::query()->where('family_tree_id', $family->primaryTree->id)->publiclyVisible()->count();
    $started = microtime(true);
    $nodes = [];
    $bytes = 0;
    $cursor = 0;
    do {
        $response = $this->getJson(route('genealogy.graph', ['demo-tree-stress-2000', 'all' => 1, 'batch' => 1000, 'cursor' => $cursor]))->assertOk();
        $bytes += strlen($response->getContent());
        $nodes = [...$nodes, ...array_column($response->json('nodes'), 'id')];
        $cursor = $response->json('meta.next_cursor');
    } while (! $response->json('meta.complete'));
    expect(count($nodes))->toBe($eligible)->and(count(array_unique($nodes)))->toBe($eligible)
        ->and($bytes / $eligible)->toBeLessThan(200)
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});
