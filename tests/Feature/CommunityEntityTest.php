<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{family: Family, tree: FamilyTree, root: Person, son: Person, admin: User, editor: User, reader: User} */
function entityFamily(string $slug = 'zain'): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة '.$slug, 'slug' => $slug]);
    $tree = $family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $root = $tree->people()->create(['first_name_ar' => 'قاسم', 'full_name_ar' => 'قاسم الجد', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $son = $tree->people()->create(['first_name_ar' => 'محمد', 'full_name_ar' => 'محمد بن قاسم', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $tree->personRelationships()->create(['person_id' => $son->id, 'related_person_id' => $root->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $tree->update(['root_person_id' => $root->id]);
    $users = [];
    foreach (['admin', 'editor', 'reader'] as $role) {
        $users[$role] = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $users[$role]->id, 'role' => $role]);
    }

    return ['family' => $family, 'tree' => $tree->fresh(), 'root' => $root, 'son' => $son, ...$users];
}

/** @return array<string, mixed> */
function entityInput(string $name, string $slug, string $type, ?CommunityEntity $parent = null, array $extra = []): array
{
    return ['name_ar' => $name, 'slug' => $slug, 'entity_type' => $type, 'parent_id' => $parent?->id, 'publication_status' => 'published', ...$extra];
}

function entityBySlug(string $slug): CommunityEntity
{
    return CommunityEntity::query()->where('slug', $slug)->sole();
}

/** Builds clan → fakhd → sub-fakhd directly, as an administrator would through the form. */
function entityChain(Family $family, string $status = 'published'): array
{
    $clan = CommunityEntity::query()->create(['family_id' => $family->id, 'entity_type' => 'clan', 'name_ar' => 'العشيرة', 'slug' => 'clan', 'publication_status' => $status]);
    $fakhd = CommunityEntity::query()->create(['family_id' => $family->id, 'parent_id' => $clan->id, 'entity_type' => 'fakhd', 'name_ar' => 'الفخذ', 'slug' => 'fakhd', 'publication_status' => $status]);
    $sub = CommunityEntity::query()->create(['family_id' => $family->id, 'parent_id' => $fakhd->id, 'entity_type' => 'sub_fakhd', 'name_ar' => 'الفخذ الفرعي', 'slug' => 'sub', 'publication_status' => $status]);

    return [$clan, $fakhd, $sub];
}

/** @return array<string, int> */
function genealogyCounts(): array
{
    return ['people' => Person::query()->withoutGlobalScopes()->count(), 'edges' => PersonRelationship::query()->count(), 'branches' => Branch::query()->count(), 'trees' => FamilyTree::query()->count()];
}

test('tribe, clan, fakhd, sub-fakhd and branch pages form one navigable hierarchy', function () {
    $context = entityFamily();
    $this->actingAs($context['admin']);
    $levels = [['قبيلة بني زيد', 'bani-zaid', 'tribe'], ['عشيرة زين', 'zain-clan', 'clan'], ['فخذ قاسم', 'qasim', 'fakhd'], ['فخذ محمد', 'mohammad', 'sub_fakhd'], ['حمولة عمر', 'omar', 'family_group']];
    $parent = null;
    foreach ($levels as [$name, $slug, $type]) {
        $this->post(route('management.entities.store', 'zain'), entityInput($name, $slug, $type, $parent, ['summary' => 'نبذة '.$name]))->assertSessionHasNoErrors();
        $parent = entityBySlug($slug);
    }
    auth()->logout();

    $this->get(route('genealogy.tree', 'zain'))->assertOk()->assertSee(route('community.entities.index', 'zain'), false);
    $index = $this->get(route('community.entities.index', 'zain'))->assertOk();
    $index->assertSeeInOrder(['قبيلة بني زيد', 'عشيرة زين', 'فخذ قاسم', 'فخذ محمد', 'حمولة عمر']);

    foreach ($levels as $position => [$name, $slug, $type]) {
        $page = $this->get(route('community.entities.show', ['zain', $slug]))->assertOk()
            ->assertSee($name)->assertSee(Branch::TYPES[$type])->assertSee('نبذة '.$name)->assertSee('التاريخ والأصول')->assertSee('شخصيات وإنجازات');
        foreach (array_slice($levels, 0, $position) as [$ancestorName, $ancestorSlug]) {
            $page->assertSee(route('community.entities.show', ['zain', $ancestorSlug]), false)->assertSee($ancestorName);
        }
        if ($next = $levels[$position + 1] ?? null) {
            $page->assertSee(route('community.entities.show', ['zain', $next[1]]), false);
        }
    }
    // The tribe lists every level below it; the clan page shows only its direct child in direct mode.
    $this->get(route('community.entities.show', ['zain', 'bani-zaid']))->assertSee('حمولة عمر');
    $this->get(route('community.entities.show', ['zain', 'zain-clan', 'scope' => 'direct']))->assertSee('فخذ قاسم')->assertDontSee('حمولة عمر');
});

test('levels are optional but a child is never placed above its parent level', function () {
    $context = entityFamily();
    $this->actingAs($context['admin']);

    $this->post(route('management.entities.store', 'zain'), entityInput('العشيرة', 'clan', 'clan'))->assertSessionHasNoErrors();
    $clan = entityBySlug('clan');
    // No tribe above the clan and no fakhd between the clan and a hamula.
    $this->post(route('management.entities.store', 'zain'), entityInput('حمولة', 'hamula', 'family_group', $clan))->assertSessionHasNoErrors();
    expect(entityBySlug('hamula')->parent_id)->toBe($clan->id);

    $this->post(route('management.entities.store', 'zain'), entityInput('قبيلة', 'tribe', 'tribe', $clan))->assertSessionHasErrors('entity');
    // Lowering the clan below a fakhd child would invert the hierarchy.
    $this->post(route('management.entities.store', 'zain'), entityInput('فخذ', 'fakhd', 'fakhd', $clan))->assertSessionHasNoErrors();
    $this->put(route('management.entities.update', ['zain', $clan->id]), entityInput('العشيرة', 'clan', 'sub_fakhd'))->assertSessionHasErrors('entity');
    expect(CommunityEntity::query()->where('slug', 'tribe')->exists())->toBeFalse()
        ->and($clan->fresh()->entity_type)->toBe('clan');
});

test('hierarchy cycles and self-parenting are rejected by the form and the model', function () {
    $context = entityFamily();
    [$clan, $fakhd, $sub] = entityChain($context['family']);
    $this->actingAs($context['admin']);

    $this->put(route('management.entities.update', ['zain', $clan->id]), entityInput('العشيرة', 'clan', 'clan', $sub))->assertSessionHasErrors('entity');
    $this->put(route('management.entities.update', ['zain', $fakhd->id]), entityInput('الفخذ', 'fakhd', 'fakhd', $fakhd))->assertSessionHasErrors('entity');
    expect($clan->fresh()->parent_id)->toBeNull()->and($fakhd->fresh()->parent_id)->toBe($clan->id);

    // Same-level nesting is allowed, so only the cycle rule stops hamula → hamula → back to the first.
    $outer = CommunityEntity::query()->create(['family_id' => $context['family']->id, 'parent_id' => $sub->id, 'entity_type' => 'family_group', 'name_ar' => 'حمولة', 'slug' => 'outer']);
    $inner = CommunityEntity::query()->create(['family_id' => $context['family']->id, 'parent_id' => $outer->id, 'entity_type' => 'family_group', 'name_ar' => 'حمولة فرعية', 'slug' => 'inner']);
    $this->put(route('management.entities.update', ['zain', $outer->id]), entityInput('حمولة', 'outer', 'family_group', $inner))->assertSessionHasErrors('entity');
    expect(fn () => $outer->update(['parent_id' => $inner->id]))->toThrow(DomainException::class, 'cycle')
        ->and($outer->fresh()->parent_id)->toBe($sub->id);
});

test('a community entity links an existing branch and tree without creating genealogy', function () {
    $context = entityFamily();
    $branch = $context['tree']->branches()->create(['name_ar' => 'فرع محمد', 'slug' => 'mohammad', 'root_person_id' => $context['son']->id, 'branch_type' => 'fakhd', 'membership_mode' => 'descendants', 'is_active' => true, 'verification_status' => 'verified']);
    $before = genealogyCounts();
    $this->actingAs($context['admin']);

    $this->post(route('management.entities.store', 'zain'), entityInput('فخذ محمد', 'mohammad-fakhd', 'fakhd', null, ['branch_id' => $branch->id]))->assertSessionHasNoErrors();
    $entity = entityBySlug('mohammad-fakhd');
    expect($entity->family_tree_id)->toBe($context['tree']->id)->and(genealogyCounts())->toBe($before);

    // One entity page per branch, and the branch cannot be deleted while a page presents it.
    $this->post(route('management.entities.store', 'zain'), entityInput('نسخة', 'copy', 'fakhd', null, ['branch_id' => $branch->id]))->assertSessionHasErrors('branch_id');
    $this->delete(route('management.branches.destroy', ['zain', $branch->id]))->assertSessionHasErrors('branch');
    expect(Branch::query()->whereKey($branch->id)->exists())->toBeTrue();
    auth()->logout();

    $this->get(route('community.entities.show', ['zain', 'mohammad-fakhd']))->assertOk()
        ->assertSee('محمد بن قاسم')->assertSee(route('genealogy.person', ['zain', $context['son']->id, 'tree' => 'main']), false)
        ->assertSee(route('genealogy.branch', ['zain', 'mohammad', 'tree' => 'main']), false);

    // An unpublished branch is not exposed through the entity page; the tree link stays.
    $branch->update(['verification_status' => 'pending_review']);
    $this->get(route('community.entities.show', ['zain', 'mohammad-fakhd']))->assertOk()
        ->assertDontSee('محمد بن قاسم')->assertDontSee(route('genealogy.branch', ['zain', 'mohammad', 'tree' => 'main']), false)
        ->assertSee(route('genealogy.tree', ['zain', 'tree' => 'main']), false);
    expect(genealogyCounts())->toBe($before);
});

test('community-only entities need no genealogical founder', function () {
    $context = entityFamily();
    $this->actingAs($context['admin'])->post(route('management.entities.store', 'zain'), entityInput('رابطة أبناء العشيرة', 'association', 'clan'))->assertSessionHasNoErrors();
    auth()->logout();

    $this->get(route('community.entities.show', ['zain', 'association']))->assertOk()
        ->assertSee('الكيانات الاجتماعية لا تشترط مؤسسًا مثبتًا')->assertSee('اكتمال صفحة التعريف: 0 من 6');
});

test('only published entities under published ancestors are public; managers may preview the rest', function () {
    $context = entityFamily();
    [$clan, $fakhd, $sub] = entityChain($context['family']);
    $fakhd->update(['publication_status' => 'draft']);

    $this->get(route('community.entities.show', ['zain', 'fakhd']))->assertNotFound();
    // Published itself, but its parent is a draft: hidden, so no breadcrumb can name the draft.
    $this->get(route('community.entities.show', ['zain', 'sub']))->assertNotFound();
    $this->get(route('community.entities.index', 'zain'))->assertOk()->assertSee('العشيرة')->assertDontSee('الفخذ');
    $this->get(route('community.entities.show', ['zain', 'clan']))->assertOk()->assertDontSee('data-entity="fakhd"', false)->assertDontSee('data-entity="sub"', false);
    $this->get(route('community.entities.show', ['zain', 'fakhd', 'preview' => 1]))->assertNotFound();

    $this->actingAs($context['reader'])->get(route('community.entities.show', ['zain', 'fakhd', 'preview' => 1]))->assertNotFound();
    $this->actingAs($context['editor'])->get(route('community.entities.show', ['zain', 'fakhd', 'preview' => 1]))->assertOk()->assertSee('معاينة');
    auth()->logout();

    $fakhd->update(['publication_status' => 'archived']);
    $this->get(route('community.entities.show', ['zain', 'fakhd']))->assertNotFound();
    $fakhd->update(['publication_status' => 'published']);
    $this->get(route('community.entities.show', ['zain', 'sub']))->assertOk()->assertSee('الفخذ');

    $context['family']->update(['is_active' => false]);
    $this->get(route('community.entities.show', ['zain', 'clan']))->assertNotFound();
    $this->get(route('community.entities.index', 'zain'))->assertNotFound();
});

test('editors draft, administrators publish and review', function () {
    $context = entityFamily();
    $this->actingAs($context['editor']);

    $this->post(route('management.entities.store', 'zain'), entityInput('العشيرة', 'clan', 'clan'))->assertSessionHasErrors('publication_status');
    $this->post(route('management.entities.store', 'zain'), entityInput('العشيرة', 'clan', 'clan', null, ['publication_status' => 'draft']))->assertSessionHasNoErrors();
    $clan = entityBySlug('clan');
    $this->put(route('management.entities.update', ['zain', $clan->id]), entityInput('العشيرة', 'clan', 'clan', null, ['publication_status' => 'draft', 'mark_reviewed' => 1]))->assertSessionHasErrors('mark_reviewed');

    $this->actingAs($context['admin'])->put(route('management.entities.update', ['zain', $clan->id]), entityInput('العشيرة', 'clan', 'clan', null, ['mark_reviewed' => 1, 'description' => 'تعريف']))->assertSessionHasNoErrors();
    expect($clan->fresh()->publication_status)->toBe('published')->and($clan->fresh()->reviewed_by)->toBe($context['admin']->id);

    // A published page changes only through a publisher.
    $this->actingAs($context['editor'])->put(route('management.entities.update', ['zain', $clan->id]), entityInput('اسم آخر', 'clan', 'clan', null, ['publication_status' => 'draft']))->assertSessionHasErrors('publication_status');
    expect($clan->fresh()->name_ar)->toBe('العشيرة');
    auth()->logout();

    $this->get(route('community.entities.show', ['zain', 'clan']))->assertOk()->assertSee('آخر مراجعة: <strong>'.now()->format('Y-m-d'), false);
    expect(AuditLog::query()->where('auditable_type', CommunityEntity::class)->where('auditable_id', $clan->id)->orderBy('id')->pluck('action')->all())->toBe(['created', 'updated']);
});

test('entity managers act only within their subtree', function () {
    $context = entityFamily();
    [$clan, $fakhd, $sub] = entityChain($context['family'], 'draft');
    $sibling = CommunityEntity::query()->create(['family_id' => $context['family']->id, 'parent_id' => $clan->id, 'entity_type' => 'fakhd', 'name_ar' => 'فخذ مجاور', 'slug' => 'sibling', 'publication_status' => 'draft']);
    $manager = $context['reader'];

    $this->actingAs($manager)->get(route('management.entities.index', 'zain'))->assertForbidden();
    $this->actingAs($context['editor'])->post(route('management.entities.managers.store', ['zain', $fakhd->id]), ['user_id' => $manager->id, 'role' => 'editor'])->assertForbidden();
    $outsider = User::factory()->create();
    $this->actingAs($context['admin'])->post(route('management.entities.managers.store', ['zain', $fakhd->id]), ['user_id' => $outsider->id, 'role' => 'editor'])->assertSessionHasErrors('user_id');
    $this->post(route('management.entities.managers.store', ['zain', $fakhd->id]), ['user_id' => $manager->id, 'role' => 'editor'])->assertSessionHasNoErrors();

    $this->actingAs($manager)->get(route('management.entities.index', 'zain'))->assertOk()->assertSee(route('management.entities.edit', ['zain', $sub->id]), false)
        ->assertDontSee(route('management.entities.edit', ['zain', $clan->id]), false)->assertDontSee(route('management.entities.edit', ['zain', $sibling->id]), false);
    $this->put(route('management.entities.update', ['zain', $sub->id]), entityInput('الفخذ الفرعي', 'sub', 'sub_fakhd', $fakhd, ['publication_status' => 'draft', 'summary' => 'نبذة']))->assertSessionHasNoErrors();
    $this->post(route('management.entities.store', 'zain'), entityInput('حمولة', 'hamula', 'family_group', $sub, ['publication_status' => 'draft']))->assertSessionHasNoErrors();
    $this->put(route('management.entities.update', ['zain', $clan->id]), entityInput('العشيرة', 'clan', 'clan', null, ['publication_status' => 'draft']))->assertForbidden();
    $this->put(route('management.entities.update', ['zain', $sibling->id]), entityInput('فخذ مجاور', 'sibling', 'fakhd', $clan, ['publication_status' => 'draft']))->assertForbidden();
    $this->post(route('management.entities.store', 'zain'), entityInput('عشيرة جديدة', 'new-root', 'clan', null, ['publication_status' => 'draft']))->assertForbidden();
    // Moving the sub-fakhd under the sibling would leave the manager's scope.
    $this->put(route('management.entities.update', ['zain', $sub->id]), entityInput('الفخذ الفرعي', 'sub', 'sub_fakhd', $sibling, ['publication_status' => 'draft']))->assertForbidden();
    $this->put(route('management.entities.update', ['zain', $sub->id]), entityInput('الفخذ الفرعي', 'sub', 'sub_fakhd', $fakhd))->assertSessionHasErrors('publication_status');

    // An entity administrator publishes inside the subtree.
    CommunityEntityManager::query()->where('user_id', $manager->id)->update(['role' => 'admin']);
    $this->put(route('management.entities.update', ['zain', $sub->id]), entityInput('الفخذ الفرعي', 'sub', 'sub_fakhd', $fakhd))->assertSessionHasNoErrors();
    expect($sub->fresh()->publication_status)->toBe('published');

    // Leaving the family ends the entity scope.
    FamilyMembership::query()->where('user_id', $manager->id)->delete();
    $this->put(route('management.entities.update', ['zain', $sub->id]), entityInput('الفخذ الفرعي', 'sub', 'sub_fakhd', $fakhd))->assertForbidden();
});

test('families cannot read or change each other\'s entities', function () {
    $zain = entityFamily();
    $other = entityFamily('other');
    [$clan] = entityChain($zain['family']);
    $foreign = CommunityEntity::query()->create(['family_id' => $other['family']->id, 'entity_type' => 'clan', 'name_ar' => 'عشيرة أخرى', 'slug' => 'other-clan', 'publication_status' => 'published']);
    $foreignBranch = $other['tree']->branches()->create(['name_ar' => 'فرع', 'slug' => 'b', 'root_person_id' => $other['son']->id, 'membership_mode' => 'descendants', 'is_active' => true, 'verification_status' => 'verified']);

    $this->actingAs($other['admin']);
    $this->get(route('management.entities.index', 'zain'))->assertForbidden();
    $this->get(route('management.entities.edit', ['zain', $clan->id]))->assertForbidden();
    $this->put(route('management.entities.update', ['zain', $clan->id]), entityInput('مختطف', 'clan', 'clan'))->assertForbidden();
    $this->get(route('management.entities.edit', ['other', $clan->id]))->assertNotFound();

    $this->actingAs($zain['admin']);
    $this->post(route('management.entities.store', 'zain'), entityInput('فخذ', 'fakhd-x', 'fakhd', $foreign))->assertNotFound();
    $this->post(route('management.entities.store', 'zain'), entityInput('فخذ', 'fakhd-x', 'fakhd', null, ['branch_id' => $foreignBranch->id]))->assertNotFound();
    $this->post(route('management.entities.store', 'zain'), entityInput('فخذ', 'fakhd-x', 'fakhd', null, ['family_tree_id' => $other['tree']->id]))->assertNotFound();
    $this->post(route('management.entities.managers.store', ['zain', $clan->id]), ['user_id' => $other['reader']->id, 'role' => 'admin'])->assertSessionHasErrors('user_id');
    auth()->logout();

    $this->get(route('community.entities.show', ['other', 'clan']))->assertNotFound();
    expect(fn () => CommunityEntity::query()->create(['family_id' => $zain['family']->id, 'parent_id' => $foreign->id, 'entity_type' => 'fakhd', 'name_ar' => 'x', 'slug' => 'x']))->toThrow(DomainException::class)
        ->and(fn () => $clan->update(['family_id' => $other['family']->id]))->toThrow(DomainException::class);
});

test('the family dashboard and navigation lead to the entity pages', function () {
    $context = entityFamily();
    entityChain($context['family']);

    $this->actingAs($context['editor'])->get(route('management.families.dashboard', 'zain'))->assertOk()
        ->assertSee(route('management.entities.index', 'zain'), false)->assertSee('صفحات الكيانات');
    $this->actingAs($context['reader'])->get(route('management.families.dashboard', 'zain'))->assertOk()
        ->assertDontSee(route('management.entities.index', 'zain'), false);
});
