<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Genealogy\BranchFocusService;
use App\Services\Genealogy\BranchMembershipService;
use App\Services\Genealogy\GenealogyTraversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function connectedContext($test): array
{
    $user = User::factory()->create();
    $user->forceFill(['is_super_admin' => true])->save();
    $test->actingAs($user)->post(route('site-admin.families.store'), [
        'name_ar' => 'عائلة آدم', 'slug' => 'adam-family', 'tree_name_ar' => 'شجرة آدم',
        'root_mode' => 'new', 'root_name_ar' => 'آدم', 'root_verification_status' => 'verified',
    ])->assertSessionHasNoErrors();
    $family = Family::query()->where('slug', 'adam-family')->sole();
    $tree = $family->primaryTree;

    return [$user, $family, $tree, $tree->rootPerson];
}

function connectedInput(string $name, ?Person $father = null, array $extra = []): array
{
    return array_replace([
        'first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical',
        'verification_status' => 'verified', 'father_id' => $father?->id,
        'father_verification_status' => 'verified',
    ], $extra);
}

function connectedAdd($test, Family $family, FamilyTree $tree, string $name, ?Person $father = null, array $extra = []): Person
{
    $test->post(route('management.people.store', [$family->slug, 'tree' => $tree->slug]), connectedInput($name, $father, $extra))
        ->assertRedirect()->assertSessionHasNoErrors();

    return $tree->people()->where('full_name_ar', $name)->sole();
}

function connectedBranch(string $name, string $slug, ?Branch $parent = null): array
{
    return [
        'branch_enabled' => 1, 'branch_name_ar' => $name, 'branch_slug' => $slug,
        'branch_is_active' => 1, 'branch_verification_status' => 'verified',
        'branch_parent_id' => $parent?->id,
    ];
}

test('family setup and add child build the complete connected example and branch focus', function () {
    [$user, $family, $tree, $adam] = connectedContext($this);
    expect($tree->family_id)->toBe($family->id)->and($adam->full_name_ar)->toBe('آدم');
    $this->get(route('management.records.create', [$family->slug, 'people', 'tree' => $tree->slug, 'father_id' => $adam->id]))
        ->assertOk()->assertSee('آدم')->assertSee('data-father-select', false);
    $mahmoud = connectedAdd($this, $family, $tree, 'محمود', $adam);
    $mohammad = connectedAdd($this, $family, $tree, 'محمد', $mahmoud);
    $ahmad = connectedAdd($this, $family, $tree, 'أحمد', $mohammad);
    $musab = connectedAdd($this, $family, $tree, 'مصعب', $mohammad, connectedBranch('فرع مصعب', 'musab'));
    $mufid = connectedAdd($this, $family, $tree, 'مفيد', $mohammad);
    $child = connectedAdd($this, $family, $tree, 'خالد', $musab);
    $branch = $musab->foundedBranches()->sole();
    expect(app(GenealogyTraversalService::class)->getAncestorPath($musab->id)->modelKeys())->toBe([$adam->id, $mahmoud->id, $mohammad->id, $musab->id])
        ->and($tree->people()->count())->toBe(7)
        ->and($tree->personRelationships()->count())->toBe(6)
        ->and(Family::query()->count())->toBe(1)
        ->and(FamilyTree::query()->count())->toBe(1)
        ->and(app(BranchMembershipService::class)->members($branch)->modelKeys())->toBe([$musab->id, $child->id]);
    $focus = collect(app(BranchFocusService::class)->getBranchFocus($branch->id)['nodes'])->keyBy(fn ($node) => $node['person']->id);
    expect($focus[$ahmad->id]['state'])->toBe('dimmed')->and($focus[$mufid->id]['state'])->toBe('dimmed');
    $isolated = app(BranchFocusService::class)->getBranchFocus($branch->id, 'isolated');
    expect(collect($isolated['nodes'])->pluck('person.id')->all())->not->toContain($ahmad->id, $mufid->id);
    $graph = $this->getJson(route('genealogy.graph', [$family->slug, 'private' => 1, 'branch' => 'musab']))->assertOk()->json();
    expect(array_unique(array_column($graph['nodes'], 'id')))->toHaveCount(count($graph['nodes']));
    $this->get(route('management.people.show', [$family->slug, $mohammad->id]))
        ->assertOk()->assertSee('محمود')->assertSee('أحمد')->assertSee('مصعب')->assertSee('مفيد')->assertSee('إضافة ابن / ابنة');
    $this->get(route('genealogy.person', [$family->slug, $mohammad->id, 'private' => 1]))->assertOk()->assertSee('الأب والأبناء المباشرون');
});

test('unknown fathers remain reviewable without becoming additional roots and link later without duplicate edges', function () {
    [$user, $family, $tree, $adam] = connectedContext($this);
    $unknown = connectedAdd($this, $family, $tree, 'غير مرتبط');
    expect($tree->fresh()->root_person_id)->toBe($adam->id)->and($unknown->outgoingRelationships()->count())->toBe(0);
    $this->get(route('management.sections.index', [$family->slug, 'people', 'parentage' => 'unknown']))->assertOk()->assertSee('غير مرتبط');
    $graph = $this->getJson(route('genealogy.graph', [$family->slug, 'private' => 1]))->assertOk()->json();
    expect(array_column($graph['nodes'], 'id'))->not->toContain($unknown->id);
    $url = route('management.people.update', [$family->slug, $unknown->id]);
    $this->put($url, connectedInput('مرتبط الآن', $adam))->assertSessionHasNoErrors();
    $id = $unknown->outgoingRelationships()->sole()->id;
    $this->put($url, connectedInput('مرتبط الآن', $adam))->assertSessionHasNoErrors();
    expect($unknown->outgoingRelationships()->sole()->id)->toBe($id);
    $other = connectedAdd($this, $family, $tree, 'أب بديل', $adam);
    $this->put($url, connectedInput('مرتبط الآن', $other))->assertSessionHasNoErrors();
    expect($unknown->outgoingRelationships()->sole()->id)->toBe($id)
        ->and($unknown->outgoingRelationships()->sole()->related_person_id)->toBe($other->id);
    $this->put($url, connectedInput('غير مرتبط مجددًا'))->assertSessionHasNoErrors();
    expect($unknown->outgoingRelationships()->count())->toBe(0)->and($tree->fresh()->root_person_id)->toBe($adam->id);
});

test('person parentage and branch changes roll back together for invalid nested ancestry', function () {
    [$user, $family, $tree, $adam] = connectedContext($this);
    $founder = connectedAdd($this, $family, $tree, 'مؤسس', $adam, connectedBranch('فرع أب', 'parent'));
    $branch = $founder->foundedBranches()->sole();
    $count = [$tree->people()->count(), PersonRelationship::query()->count(), Branch::query()->count(), AuditLog::query()->count()];
    $this->post(route('management.people.store', $family->slug),
        connectedInput('لن يحفظ', $adam, connectedBranch('فرع غير صالح', 'invalid', $branch)))
        ->assertSessionHasErrors('change');
    expect([$tree->people()->count(), PersonRelationship::query()->count(), Branch::query()->count(), AuditLog::query()->count()])->toBe($count);
    $child = connectedAdd($this, $family, $tree, 'مؤسس فرعي', $founder, connectedBranch('فرع ابن', 'nested', $branch));
    $auditCount = AuditLog::query()->count();
    $this->put(route('management.people.update', [$family->slug, $child->id]), connectedInput('اسم يجب ألا يحفظ', $adam))
        ->assertSessionHasErrors('change');
    expect($child->fresh()->full_name_ar)->toBe('مؤسس فرعي')
        ->and($child->outgoingRelationships()->sole()->related_person_id)->toBe($founder->id)
        ->and(AuditLog::query()->count())->toBe($auditCount);
    // An explicit simultaneous branch-parent update is checked against the final graph.
    $nested = $child->foundedBranches()->sole();
    $this->put(route('management.people.update', [$family->slug, $child->id]), connectedInput('نقل صالح', $adam,
        connectedBranch('فرع مستقل', 'nested') + ['founded_branch_id' => $nested->id]))->assertSessionHasNoErrors();
    expect($nested->fresh()->parent_branch_id)->toBeNull()->and($nested->fresh()->root_person_id)->toBe($child->id);
});

test('pending parentage supports nested branch structure and edits do not duplicate or silently delete branches', function () {
    [$user, $family, $tree, $adam] = connectedContext($this);
    $parent = connectedAdd($this, $family, $tree, 'أصل فرع', $adam, connectedBranch('الأصل', 'base'));
    $branch = $parent->foundedBranches()->sole();
    $founder = connectedAdd($this, $family, $tree, 'فرعي', $parent,
        connectedBranch('فرعي', 'sub', $branch) + ['father_verification_status' => 'pending_review']);
    $sub = $founder->foundedBranches()->sole();
    $url = route('management.people.update', [$family->slug, $founder->id]);
    $this->put($url, connectedInput('فرعي', $parent, connectedBranch('اسم محدث', 'sub', $branch)))->assertSessionHasNoErrors();
    expect($founder->foundedBranches()->count())->toBe(1)->and($sub->fresh()->name_ar)->toBe('اسم محدث');
    $this->put($url, connectedInput('فرعي', $parent, ['branch_enabled' => 0]))->assertSessionHasNoErrors();
    expect($founder->foundedBranches()->count())->toBe(1);
    $this->get(route('management.records.edit', [$family->slug, 'people', $founder->id]))->assertOk()->assertSee('اسم محدث');
    // Existing explicit deletion safeguards still apply.
    $this->delete(route('management.branches.destroy', [$family->slug, $branch->id]))->assertSessionHasErrors('branch');
});

test('cycles self parenting root fathers and duplicate primary fathers are rejected atomically', function () {
    [$user, $family, $tree, $adam] = connectedContext($this);
    $child = connectedAdd($this, $family, $tree, 'ابن', $adam);
    $grandchild = connectedAdd($this, $family, $tree, 'حفيد', $child);
    foreach ([[$child, $child], [$child, $grandchild], [$adam, $grandchild]] as [$person, $father]) {
        $count = AuditLog::query()->count();
        $this->put(route('management.people.update', [$family->slug, $person->id]),
            connectedInput('اسم مرفوض', $father, ['father_verification_status' => 'pending_review']))->assertSessionHasErrors();
        expect($person->fresh()->full_name_ar)->toBe($person->full_name_ar)->and(AuditLog::query()->count())->toBe($count);
    }
    $this->post(route('management.relationships.store', $family->slug), ['person_id' => $child->id, 'father_id' => $grandchild->id, 'verification_status' => 'pending_review'])->assertSessionHasErrors('relationship');
    $this->post(route('management.relationships.store', $family->slug), ['person_id' => $adam->id, 'father_id' => $child->id, 'verification_status' => 'pending_review'])->assertSessionHasErrors('relationship');
    expect($tree->personRelationships()->count())->toBe(2);
});

test('empty tree root setup supports new and existing roots and explicit root replacement', function () {
    [$user, $family] = connectedContext($this);
    $this->post(route('site-admin.trees.store', $family->id), ['name_ar' => 'فارغة', 'slug' => 'empty', 'root_mode' => 'empty'])->assertSessionHasNoErrors();
    $tree = $family->familyTrees()->where('slug', 'empty')->sole();
    $this->get(route('management.families.show', [$family->slug, 'tree' => 'empty']))->assertOk()->assertSee('إضافة جذر الشجرة الآن');
    $person = connectedAdd($this, $family, $tree, 'جذر لاحق', null, ['make_root' => 1]);
    expect($tree->fresh()->root_person_id)->toBe($person->id);
    $replacement = connectedAdd($this, $family, $tree, 'جذر بديل');
    $rootUrl = route('management.trees.update', [$family->slug, $tree->id, 'tree' => 'empty']);
    $this->put($rootUrl, ['root_mode' => 'existing', 'root_person_id' => $replacement->id])->assertSessionHasNoErrors();
    $this->put(route('management.people.update', [$family->slug, $person->id, 'tree' => 'empty']), connectedInput('الجذر السابق', $replacement))->assertSessionHasNoErrors();
    $this->put($rootUrl, ['root_mode' => 'existing', 'root_person_id' => $person->id])->assertSessionHasErrors('root_person_id');
    expect($tree->fresh()->root_person_id)->toBe($replacement->id);
    $this->put($rootUrl, ['root_mode' => 'new', 'root_name_ar' => 'جذر منشأ من الإعدادات'])->assertSessionHasNoErrors();
    expect($tree->fresh()->rootPerson->full_name_ar)->toBe('جذر منشأ من الإعدادات');
});

test('father branch and root selections cannot cross family tree or founder boundaries', function () {
    [$user, $family, $tree, $adam] = connectedContext($this);
    $otherFamily = Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other']);
    $otherTree = $otherFamily->familyTrees()->create(['name_ar' => 'أخرى', 'slug' => 'main', 'is_primary' => true]);
    $other = $otherTree->people()->create(['first_name_ar' => 'خارج', 'full_name_ar' => 'خارج']);
    $second = $family->familyTrees()->create(['name_ar' => 'ثانية', 'slug' => 'second']);
    $otherInFamily = $second->people()->create(['first_name_ar' => 'شجرة أخرى', 'full_name_ar' => 'شجرة أخرى']);
    foreach ([$other, $otherInFamily] as $outsider) {
        $count = $tree->people()->count();
        $this->post(route('management.people.store', $family->slug), connectedInput('مرفوض', $outsider))->assertNotFound();
        expect($tree->people()->count())->toBe($count);
        $this->get(route('management.records.create', [$family->slug, 'people', 'father_id' => $outsider->id]))->assertNotFound();
        $this->put(route('management.trees.update', [$family->slug, $tree->id]), ['root_mode' => 'existing', 'root_person_id' => $outsider->id])->assertSessionHasErrors('root_person_id');
    }
    $founder = connectedAdd($this, $family, $tree, 'مؤسس', $adam, connectedBranch('فرعه', 'owned'));
    $owned = $founder->foundedBranches()->sole();
    $this->put(route('management.people.update', [$family->slug, $adam->id]), connectedInput('آدم', null, connectedBranch('استيلاء', 'owned') + ['founded_branch_id' => $owned->id]))->assertNotFound();
    expect($owned->fresh()->root_person_id)->toBe($founder->id);
    $this->getJson(route('management.lookups.people', [$family->slug, 'q' => 'خارج']))->assertOk()->assertJsonCount(0, 'data');
});

test('roles govern connected forms root setup graph actions and change review remains available', function () {
    [$super, $family, $tree, $adam] = connectedContext($this);
    foreach (['reader', 'editor', 'admin'] as $role) {
        $user = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $user->id, 'role' => $role]);
        $this->actingAs($user);
        $this->get(route('management.records.create', [$family->slug, 'people', 'father_id' => $adam->id]))->assertStatus($role === 'reader' ? 403 : 200);
        $graph = $this->getJson(route('genealogy.graph', [$family->slug, 'private' => 1]))->assertOk()->json();
        expect($graph['nodes'][0]['add_child_url'] !== null)->toBe($role !== 'reader');
        if ($role === 'reader') {
            $this->post(route('management.people.store', $family->slug), connectedInput('مرفوض', $adam))->assertForbidden();
            $this->post(route('management.changes.store', $family->slug), [
                'kind' => 'person', 'operation' => 'create', 'reason' => 'اقتراح قارئ',
                'first_name_ar' => 'مقترح', 'full_name_ar' => 'مقترح', 'status' => 'historical', 'verification_status' => 'pending_review',
            ])->assertSessionHasNoErrors();
            expect(ChangeRequest::query()->count())->toBe(1);
        } else {
            connectedAdd($this, $family, $tree, 'شخص '.$role, $adam);
        }
        if ($role !== 'admin') {
            $this->put(route('management.trees.update', [$family->slug, $tree->id]), ['root_mode' => 'empty'])->assertForbidden();
        }
    }
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->post(route('management.people.store', $family->slug), connectedInput('غير مخول', $adam))->assertForbidden();
    $this->actingAs($super)->get(route('management.changes.create', [$family->slug, 'kind' => 'person']))->assertOk()->assertDontSee('data-founder-toggle', false);
});
