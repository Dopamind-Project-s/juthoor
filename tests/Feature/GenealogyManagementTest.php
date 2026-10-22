<?php

use App\Models\AuditLog;
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

/** @return array{family: Family, tree: FamilyTree, root: Person, child: Person} */
function managementGraph(string $slug): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة '.$slug, 'slug' => $slug]);
    $tree = FamilyTree::query()->create([
        'family_id' => $family->id,
        'name_ar' => 'الشجرة الأساسية',
        'slug' => 'main',
        'is_primary' => true,
        'is_active' => true,
    ]);
    $root = Person::query()->create([
        'family_tree_id' => $tree->id,
        'canonical_key' => $slug.'-root',
        'first_name_ar' => 'الجذر',
        'full_name_ar' => 'جذر '.$slug,
        'status' => Person::STATUS_HISTORICAL,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
    $child = Person::query()->create([
        'family_tree_id' => $tree->id,
        'canonical_key' => $slug.'-child',
        'first_name_ar' => 'الابن',
        'full_name_ar' => 'ابن '.$slug,
        'status' => Person::STATUS_ACTIVE,
        'is_living' => true,
        'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
    ]);
    $tree->update(['root_person_id' => $root->id]);

    return compact('family', 'tree', 'root', 'child');
}

function giveFamilyRole(User $user, Family $family, string $role): FamilyMembership
{
    return FamilyMembership::query()->create([
        'user_id' => $user->id,
        'family_id' => $family->id,
        'role' => $role,
    ]);
}

function personPayload(string $key = 'new-person'): array
{
    return [
        'first_name_ar' => 'شخص',
        'full_name_ar' => 'شخص جديد',
        'canonical_key' => $key,
        'status' => Person::STATUS_HISTORICAL,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ];
}

test('the family dashboard lists only memberships belonging to the signed in user', function () {
    $first = managementGraph('first');
    $second = managementGraph('second');
    $user = User::factory()->create();
    giveFamilyRole($user, $first['family'], FamilyMembership::ROLE_READER);

    $this->actingAs($user)->get(route('management.families.index'))
        ->assertOk()
        ->assertSee($first['family']->name_ar)
        ->assertDontSee($second['family']->name_ar);
});

test('a reader can view private family records but cannot modify them', function () {
    $graph = managementGraph('reader-family');
    $reader = User::factory()->create();
    giveFamilyRole($reader, $graph['family'], FamilyMembership::ROLE_READER);

    $this->actingAs($reader)->get(route('management.families.show', $graph['family']->slug))
        ->assertOk()
        ->assertSee($graph['child']->full_name_ar)
        ->assertDontSee('إضافة شخص');
    $this->actingAs($reader)->post(route('management.people.store', $graph['family']->slug), personPayload())
        ->assertForbidden();
});

test('an editor can create and update a person and every change is audited', function () {
    $graph = managementGraph('editable');
    $editor = User::factory()->create();
    giveFamilyRole($editor, $graph['family'], FamilyMembership::ROLE_EDITOR);

    $this->actingAs($editor)->post(route('management.people.store', $graph['family']->slug), personPayload())
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'تمت إضافة الشخص بنجاح.');
    $person = $graph['tree']->people()->where('canonical_key', 'new-person')->sole();

    $this->actingAs($editor)->put(route('management.people.update', [$graph['family']->slug, $person->id]), [
        ...personPayload(), 'full_name_ar' => 'الاسم بعد التحديث',
    ])->assertSessionHasNoErrors();

    expect($person->refresh()->full_name_ar)->toBe('الاسم بعد التحديث');
    $logs = AuditLog::query()->where('auditable_type', Person::class)->where('auditable_id', $person->id)->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->first()->user_id)->toBe($editor->id)
        ->and($logs->first()->family_id)->toBe($graph['family']->id)
        ->and($logs->last()->before['full_name_ar'])->toBe('شخص جديد')
        ->and($logs->last()->after['full_name_ar'])->toBe('الاسم بعد التحديث');
});

test('route identifiers cannot cross family boundaries for people or relationships', function () {
    $first = managementGraph('alpha');
    $second = managementGraph('beta');
    $editor = User::factory()->create();
    giveFamilyRole($editor, $first['family'], FamilyMembership::ROLE_EDITOR);
    giveFamilyRole($editor, $second['family'], FamilyMembership::ROLE_READER);

    $this->actingAs($editor)->put(
        route('management.people.update', [$first['family']->slug, $second['child']->id]),
        personPayload('attempt'),
    )->assertNotFound();

    $this->actingAs($editor)->post(route('management.relationships.store', $first['family']->slug), [
        'person_id' => $first['child']->id,
        'father_id' => $second['root']->id,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ])->assertNotFound();
});

test('relationship integrity rejects a second father and genealogy cycles with Arabic errors', function () {
    $graph = managementGraph('integrity');
    $editor = User::factory()->create();
    giveFamilyRole($editor, $graph['family'], FamilyMembership::ROLE_EDITOR);
    $otherFather = Person::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'canonical_key' => 'other-father',
        'first_name_ar' => 'أب',
        'full_name_ar' => 'أب آخر',
        'status' => Person::STATUS_HISTORICAL,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);

    $this->actingAs($editor)->post(route('management.relationships.store', $graph['family']->slug), [
        'person_id' => $graph['child']->id,
        'father_id' => $graph['root']->id,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ])->assertSessionHasNoErrors();
    $this->actingAs($editor)->post(route('management.relationships.store', $graph['family']->slug), [
        'person_id' => $graph['child']->id,
        'father_id' => $otherFather->id,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ])->assertSessionHasErrors(['relationship' => 'للشخص أب أساسي مسجل بالفعل.']);
    $this->actingAs($editor)->post(route('management.relationships.store', $graph['family']->slug), [
        'person_id' => $graph['root']->id,
        'father_id' => $graph['child']->id,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ])->assertSessionHasErrors(['relationship']);
});

test('branch management reuses founder integrity and records changes', function () {
    $graph = managementGraph('branches');
    $editor = User::factory()->create();
    giveFamilyRole($editor, $graph['family'], FamilyMembership::ROLE_EDITOR);

    $this->actingAs($editor)->post(route('management.branches.store', $graph['family']->slug), [
        'name_ar' => 'فرع تجريبي',
        'slug' => 'example',
        'root_person_id' => $graph['child']->id,
        'is_active' => '1',
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ])->assertSessionHasNoErrors();

    $branch = $graph['tree']->branches()->sole();
    expect($branch->root_person_id)->toBe($graph['child']->id)
        ->and(AuditLog::query()->where('auditable_type', Branch::class)->where('auditable_id', $branch->id)->count())->toBe(1);
});

test('safe deletion protects roots founders relationships and parent branches', function () {
    $graph = managementGraph('deletion');
    $editor = User::factory()->create();
    giveFamilyRole($editor, $graph['family'], FamilyMembership::ROLE_EDITOR);
    $relationship = PersonRelationship::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'person_id' => $graph['child']->id,
        'related_person_id' => $graph['root']->id,
        'relationship_type' => PersonRelationship::TYPE_FATHER,
        'is_primary' => true,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
    $parent = Branch::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'root_person_id' => $graph['root']->id,
        'name_ar' => 'الأصل', 'slug' => 'parent', 'is_active' => true,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);
    Branch::query()->create([
        'family_tree_id' => $graph['tree']->id,
        'parent_branch_id' => $parent->id,
        'root_person_id' => $graph['child']->id,
        'name_ar' => 'الفرع', 'slug' => 'child', 'is_active' => true,
        'verification_status' => Source::VERIFICATION_VERIFIED,
    ]);

    $this->actingAs($editor)->delete(route('management.people.destroy', [$graph['family']->slug, $graph['root']->id]))
        ->assertSessionHasErrors(['person']);
    $this->actingAs($editor)->delete(route('management.relationships.destroy', [$graph['family']->slug, $relationship->id]))
        ->assertSessionHasErrors(['relationship']);
    $this->actingAs($editor)->delete(route('management.branches.destroy', [$graph['family']->slug, $parent->id]))
        ->assertSessionHasErrors(['branch']);
});

test('only an administrator can manage memberships and the last administrator is protected', function () {
    $graph = managementGraph('members');
    $admin = User::factory()->create();
    $editor = User::factory()->create();
    $newMember = User::factory()->create();
    $adminMembership = giveFamilyRole($admin, $graph['family'], FamilyMembership::ROLE_ADMIN);
    giveFamilyRole($editor, $graph['family'], FamilyMembership::ROLE_EDITOR);

    $this->actingAs($editor)->post(route('management.memberships.store', $graph['family']->slug), [
        'email' => $newMember->email, 'role' => FamilyMembership::ROLE_READER,
    ])->assertForbidden();
    $this->actingAs($admin)->put(route('management.memberships.update', [$graph['family']->slug, $adminMembership->id]), [
        'role' => 'super_admin',
    ])->assertSessionHasErrors(['role']);
    $this->actingAs($admin)->post(route('management.memberships.store', $graph['family']->slug), [
        'email' => $newMember->email, 'role' => FamilyMembership::ROLE_READER,
    ])->assertSessionHasNoErrors();
    $this->actingAs($admin)->delete(route('management.memberships.destroy', [$graph['family']->slug, $adminMembership->id]))
        ->assertSessionHasErrors(['role' => 'يجب أن يبقى للعائلة مدير واحد على الأقل.']);

    expect($newMember->roleFor($graph['family']))->toBe(FamilyMembership::ROLE_READER)
        ->and(AuditLog::query()->where('auditable_type', FamilyMembership::class)->count())->toBe(1);
});
