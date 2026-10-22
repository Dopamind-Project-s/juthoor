<?php

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * root (verified) → narrative (pending, explicitly published) → published child; the root also has a draft child
 * (whose own published child must not reveal it), a living child and an unresolved child with a stale narrative flag.
 *
 * @return array<string, mixed>
 */
function visibilityFixture(): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة الرؤية', 'slug' => 'visible']);
    $tree = $family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $person = fn (string $name, array $extra = []) => $tree->people()->create(['first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'is_living' => false, 'verification_status' => 'verified', ...$extra]);
    $edge = fn (Person $child, Person $father, array $extra = []) => $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified', ...$extra]);
    $people = ['root' => $person('الجد العام')];
    $tree->update(['root_person_id' => $people['root']->id]);
    $people['narrative'] = $person('رواية منشورة', ['verification_status' => 'pending_review', 'public_narrative' => true]);
    $edge($people['narrative'], $people['root'], ['verification_status' => 'pending_review', 'public_narrative' => true]);
    $people['child'] = $person('ابن الرواية');
    $edge($people['child'], $people['narrative']);
    $people['draft'] = $person('مسودة مخفية', ['verification_status' => 'pending_review']);
    $edge($people['draft'], $people['root'], ['verification_status' => 'pending_review']);
    $people['orphan'] = $person('ابن المسودة');
    $edge($people['orphan'], $people['draft']);
    $people['living'] = $person('حي مخفي', ['is_living' => true]);
    $edge($people['living'], $people['root']);
    $people['disputed'] = $person('رواية متنازع عليها', ['verification_status' => 'unresolved', 'public_narrative' => true]);
    $edge($people['disputed'], $people['root'], ['verification_status' => 'unresolved', 'public_narrative' => true]);
    $tree->branches()->create(['name_ar' => 'فرع الرواية', 'slug' => 'narrative', 'root_person_id' => $people['narrative']->id, 'is_active' => true, 'verification_status' => 'verified']);
    $tree->branches()->create(['name_ar' => 'فرع المسودة', 'slug' => 'draft', 'root_person_id' => $people['draft']->id, 'is_active' => true, 'verification_status' => 'verified']);

    return ['family' => $family, 'tree' => $tree->fresh(), 'people' => $people];
}

/** @return array<int, string> names returned by every public JSON endpoint */
function publicGraphNames($test): array
{
    $names = [];
    foreach ([route('genealogy.graph', 'visible'), route('genealogy.graph', ['visible', 'all' => 1]), route('genealogy.graph', ['visible', 'branch' => 'narrative']), route('genealogy.graph', ['visible', 'branch' => 'narrative', 'mode' => 'isolated'])] as $url) {
        $names = [...$names, ...collect($test->getJson($url)->assertOk()->json('nodes'))->pluck('name')->all()];
    }
    foreach (['الجد', 'رواية', 'ابن', 'مسودة', 'حي'] as $term) {
        $names = [...$names, ...collect($test->getJson(route('genealogy.search', ['visible', 'q' => $term]))->assertOk()->json('data'))->flatMap(fn ($row) => [$row['text'], $row['lineage'] ?? ''])->all()];
    }

    return array_values(array_unique(array_filter($names)));
}

test('every public endpoint applies one publication rule for visitors and signed in members alike', function (?string $role) {
    $fixture = visibilityFixture();
    if ($role === 'super') {
        $this->actingAs(User::factory()->create()->forceFill(['is_super_admin' => true]));
    } elseif ($role) {
        $user = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $fixture['family']->id, 'user_id' => $user->id, 'role' => $role]);
        $this->actingAs($user);
    }

    $names = implode(' | ', publicGraphNames($this));
    // ابن المسودة is itself published; only the edge to its hidden father is withheld.
    foreach (['الجد العام', 'رواية منشورة', 'ابن الرواية', 'ابن المسودة'] as $visible) {
        expect($names)->toContain($visible);
    }
    foreach (['مسودة مخفية', 'حي مخفي', 'رواية متنازع عليها'] as $hidden) {
        expect($names)->not->toContain($hidden);
    }
})->with(['visitor' => null, 'reader' => 'reader', 'editor' => 'editor', 'family admin' => 'admin', 'super admin' => 'super']);

test('public person and branch pages never reveal hidden people or their names', function () {
    $fixture = visibilityFixture();
    $people = $fixture['people'];

    $this->get(route('genealogy.person', ['visible', $people['child']->id]))->assertOk()->assertSee('رواية منشورة')->assertSee('الجد العام');
    $this->get(route('genealogy.person', ['visible', $people['root']->id]))->assertOk()
        ->assertSee('رواية منشورة')->assertDontSee('مسودة مخفية')->assertDontSee('حي مخفي')->assertDontSee('رواية متنازع عليها');
    foreach (['draft', 'living', 'disputed'] as $key) {
        $this->get(route('genealogy.person', ['visible', $people[$key]->id]))->assertNotFound();
    }
    $this->get(route('genealogy.person', ['visible', $people['orphan']->id]))->assertOk()->assertDontSee('مسودة مخفية');
    $this->get(route('genealogy.tree', 'visible'))->assertOk()->assertSee('فرع الرواية')->assertDontSee('فرع المسودة');
    $this->get(route('genealogy.branch', ['visible', 'narrative']))->assertOk();
    $this->get(route('genealogy.branch', ['visible', 'draft']))->assertNotFound();
    $this->getJson(route('genealogy.graph', ['visible', 'branch' => 'draft']))->assertNotFound();
});

test('the private preview shows drafts only to family members with a verified email', function () {
    $fixture = visibilityFixture();
    $reader = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $fixture['family']->id, 'user_id' => $reader->id, 'role' => 'reader']);
    $private = route('genealogy.graph', ['visible', 'all' => 1, 'private' => 1]);

    $this->getJson($private)->assertForbidden();
    $this->actingAs(User::factory()->create())->getJson($private)->assertForbidden();
    expect(collect($this->actingAs($reader)->getJson($private)->assertOk()->json('nodes'))->pluck('name'))->toContain('مسودة مخفية', 'حي مخفي');
});

test('cross-family linking still requires verified ancestors rather than published narratives', function () {
    $fixture = visibilityFixture();

    expect(Person::query()->publiclyVisible()->whereKey($fixture['people']['narrative']->id)->exists())->toBeTrue()
        ->and(Person::query()->verifiedHistorical()->whereKey($fixture['people']['narrative']->id)->exists())->toBeFalse();
});

test('editors cannot change a published narrative directly', function () {
    $fixture = visibilityFixture();
    $editor = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $fixture['family']->id, 'user_id' => $editor->id, 'role' => 'editor']);

    $this->actingAs($editor)->put(route('management.people.update', ['visible', $fixture['people']['narrative']->id]), [
        'first_name_ar' => 'تعديل', 'full_name_ar' => 'تعديل', 'status' => 'historical', 'verification_status' => 'pending_review',
    ])->assertSessionHasErrors('change');
    expect($fixture['people']['narrative']->fresh()->full_name_ar)->toBe('رواية منشورة');
});

test('edges to hidden fathers are withheld from every public graph response', function () {
    $fixture = visibilityFixture();
    $hiddenIds = collect(['draft', 'living', 'disputed'])->map(fn ($key) => $fixture['people'][$key]->id);

    foreach ([route('genealogy.graph', 'visible'), route('genealogy.graph', ['visible', 'all' => 1])] as $url) {
        $response = $this->getJson($url)->assertOk();
        expect(collect($response->json('edges'))->pluck('parent_id')->intersect($hiddenIds))->toBeEmpty()
            ->and(collect($response->json('edges'))->pluck('child_id')->intersect($hiddenIds))->toBeEmpty();
    }
});
