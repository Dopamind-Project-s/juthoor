<?php

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\User;
use Database\Seeders\DevelopmentAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function adminDemo(): array
{
    app(DevelopmentAdminSeeder::class)->run();

    return [
        User::query()->where('username', 'superadmin')->sole(),
        User::query()->where('username', 'familyadmin')->sole(),
        Family::query()->firstOrFail(),
    ];
}

test('demo seeding is idempotent preserves passwords and roles and supports both login identifiers', function () {
    [$super, $admin, $family] = adminDemo();
    $hash = $super->password;
    app(DevelopmentAdminSeeder::class)->run();
    expect(User::query()->count())->toBe(4)
        ->and(FamilyMembership::query()->count())->toBe(3)
        ->and($super->fresh()->password)->toBe($hash)
        ->and(Hash::check('password', $hash))->toBeTrue()
        ->and($super->hasVerifiedEmail())->toBeTrue();
    $this->post(route('login.store'), ['email' => 'superadmin', 'password' => 'password'])->assertRedirect(route('site-admin.dashboard'));
    $this->assertAuthenticatedAs($super);
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $super->email, 'password' => 'password'])->assertRedirect(route('site-admin.dashboard'));
    $super->update(['password' => 'changed-password']);
    $membership = $admin->familyMemberships()->sole();
    $membership->update(['role' => 'editor']);
    app(DevelopmentAdminSeeder::class)->run();
    expect(Hash::check('changed-password', $super->fresh()->password))->toBeTrue()
        ->and($membership->fresh()->role)->toBe('editor');
});

test('demo seeding refuses production and identifier collisions without partial changes', function () {
    $this->app->instance('env', 'production');
    expect(fn () => app(DevelopmentAdminSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
    $this->app->instance('env', 'testing');
    User::factory()->create(['username' => 'editor', 'email' => 'real@example.test']);
    expect(fn () => app(DevelopmentAdminSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(1)->and(Family::query()->count())->toBe(0);
});

test('super admin resource lists forms and details render with real records', function () {
    [$super, $admin, $family] = adminDemo();
    Notification::fake();
    $this->actingAs($super)->post(route('invitations.store', $family->slug), ['email' => 'invite@example.test', 'role' => 'reader'])->assertSessionHasNoErrors();
    $records = [
        'families' => $family, 'trees' => $family->primaryTree, 'users' => $admin,
        'memberships' => $admin->familyMemberships()->sole(),
        'invitations' => FamilyInvitation::query()->firstOrFail(),
        'audit' => AuditLog::query()->firstOrFail(),
    ];
    $this->get(route('site-admin.dashboard'))->assertOk();
    foreach ($records as $resource => $record) {
        $this->get(route('site-admin.resources.index', $resource))->assertOk();
        $this->get(route('site-admin.resources.index', [$resource, 'q' => 'no-match']))->assertOk()->assertSee('لا توجد سجلات');
        $this->get(route('site-admin.resources.show', [$resource, $record->id]))->assertOk();
        if ($resource !== 'audit') {
            $this->get(route('site-admin.resources.create', [$resource, 'family_id' => $family->id]))->assertOk();
            $this->get(route('site-admin.resources.edit', [$resource, $record->id]))->assertOk();
        }
    }
    $this->actingAs($admin)->get(route('site-admin.resources.index', 'users'))->assertForbidden();
    $this->get(route('site-admin.resources.edit', ['users', $super->id]))->assertForbidden();
});

test('family pages preserve reader editor admin permissions and family isolation', function () {
    [$super, $admin, $family] = adminDemo();
    $tree = $family->primaryTree;
    $person = $tree->people()->create(['first_name_ar' => 'أحمد', 'full_name_ar' => 'أحمد', 'canonical_key' => 'test']);
    $branch = $tree->branches()->create(['name_ar' => 'فرع', 'slug' => 'test', 'root_person_id' => $person->id, 'is_active' => false]);
    $father = $tree->people()->create(['first_name_ar' => 'علي', 'full_name_ar' => 'علي']);
    $relationship = $tree->personRelationships()->create(['person_id' => $person->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true]);
    Notification::fake();
    $this->actingAs($admin)->post(route('invitations.store', $family->slug), ['email' => 'pending@example.test', 'role' => 'reader']);
    $records = ['people' => $person, 'branches' => $branch, 'relationships' => $relationship, 'trees' => $tree, 'members' => $admin->familyMemberships()->sole(), 'invitations' => FamilyInvitation::query()->sole()];
    foreach (['familyadmin', 'editor', 'reader'] as $username) {
        $user = User::query()->where('username', $username)->sole();
        $this->actingAs($user)->get(route('management.families.show', $family->slug))->assertOk();
        foreach ($records as $section => $record) {
            $private = in_array($section, ['members', 'invitations']);
            $canView = ! $private || $username === 'familyadmin';
            $canEdit = in_array($section, ['trees', 'members', 'invitations']) ? $username === 'familyadmin' : $username !== 'reader';
            $this->get(route('management.sections.index', [$family->slug, $section]))->assertStatus($canView ? 200 : 403);
            $this->get(route('management.records.show', [$family->slug, $section, $record->id]))->assertStatus($canView ? 200 : 403);
            $this->get(route('management.records.create', [$family->slug, $section]))->assertStatus($canEdit ? 200 : 403);
            $this->get(route('management.records.edit', [$family->slug, $section, $record->id]))->assertStatus($canEdit ? 200 : 403);
        }
    }
    $other = Family::query()->create(['name_ar' => 'عائلة أخرى', 'slug' => 'other']);
    $otherTree = $other->familyTrees()->create(['name_ar' => 'شجرة', 'slug' => 'main', 'is_primary' => true]);
    $outsider = $otherTree->people()->create(['first_name_ar' => 'خاص', 'full_name_ar' => 'خاص']);
    $this->actingAs($admin)->get(route('management.sections.index', [$other->slug, 'people']))->assertForbidden();
    $this->get(route('management.records.edit', [$family->slug, 'people', $outsider->id]))->assertNotFound();
    $this->post(route('site-admin.trees.store', $other->id), ['name_ar' => 'غير مسموح', 'slug' => 'blocked'])->assertForbidden();
});

test('new user tree membership invitation and relationship forms persist authorized changes', function () {
    [$super, $admin, $family] = adminDemo();
    Notification::fake();
    $this->actingAs($super)->post(route('site-admin.users.store'), [
        'name' => 'مستخدم جديد', 'email' => 'new@example.test', 'username' => 'newuser',
        'password' => 'new-password', 'password_confirmation' => 'new-password', 'is_active' => 1,
    ])->assertSessionHasNoErrors()->assertRedirect();
    $new = User::query()->where('username', 'newuser')->sole();
    expect(Hash::check('new-password', $new->password))->toBeTrue();
    $this->put(route('site-admin.users.update', $new->id), ['name' => 'اسم محدث', 'email' => $new->email, 'is_active' => 1])->assertSessionHasNoErrors();
    expect($new->fresh()->name)->toBe('اسم محدث');
    $this->actingAs($admin)->post(route('management.memberships.store', $family->slug), ['email' => $new->email, 'role' => 'reader'])->assertSessionHasNoErrors();
    $this->post(route('site-admin.trees.store', $family->id), ['name_ar' => 'شجرة جديدة', 'slug' => 'second'])->assertSessionHasNoErrors();
    $second = $family->familyTrees()->where('slug', 'second')->sole();
    $this->put(route('site-admin.trees.update', [$family->id, $second->id]), ['name_ar' => 'شجرة محدثة', 'is_active' => 1])->assertSessionHasNoErrors();
    expect($second->fresh()->name_ar)->toBe('شجرة محدثة');
    $this->post(route('invitations.store', $family->slug), ['email' => 'invited@example.test', 'role' => 'reader'])->assertSessionHasNoErrors();
    $invitation = FamilyInvitation::query()->sole();
    $this->put(route('invitations.update', [$family->slug, $invitation->id]), ['role' => 'editor'])->assertSessionHasNoErrors();
    expect($invitation->fresh()->role)->toBe('editor');
    $tree = $family->primaryTree;
    $child = $tree->people()->create(['first_name_ar' => 'طفل', 'full_name_ar' => 'طفل']);
    $father = $tree->people()->create(['first_name_ar' => 'أب', 'full_name_ar' => 'أب']);
    $this->post(route('management.relationships.store', $family->slug), ['person_id' => $child->id, 'father_id' => $father->id, 'verification_status' => 'pending_review'])->assertSessionHasNoErrors();
    $edge = $tree->personRelationships()->sole();
    $this->put(route('management.relationships.update', [$family->slug, $edge->id]), ['person_id' => $child->id, 'father_id' => $father->id, 'verification_status' => 'verified'])->assertSessionHasNoErrors();
    expect($edge->fresh()->verification_status)->toBe('verified');
    $reader = User::query()->where('username', 'reader')->sole();
    $this->actingAs($reader)->put(route('management.relationships.update', [$family->slug, $edge->id]), ['person_id' => $child->id, 'father_id' => $father->id, 'verification_status' => 'pending_review'])->assertForbidden();
    $this->post(route('site-admin.users.store'), ['name' => 'blocked'])->assertForbidden();
    $this->put(route('invitations.update', [$family->slug, $invitation->id]), ['role' => 'admin'])->assertForbidden();
});

test('demo seeding reuses existing genealogy without changing names or trees', function () {
    $family = Family::query()->create(['name_ar' => 'الزعبي', 'slug' => 'al-zoubi']);
    $tree = $family->familyTrees()->create(['name_ar' => 'الأسماء الأصلية', 'slug' => 'original', 'is_primary' => true]);
    $person = $tree->people()->create(['first_name_ar' => 'اسم أصلي', 'full_name_ar' => 'اسم أصلي محفوظ']);
    $before = [$family->fresh()->getAttributes(), $tree->fresh()->getAttributes(), $person->fresh()->getAttributes()];
    app(DevelopmentAdminSeeder::class)->run();
    app(DevelopmentAdminSeeder::class)->run();
    expect([$family->fresh()->getAttributes(), $tree->fresh()->getAttributes(), $person->fresh()->getAttributes()])->toBe($before)
        ->and(Family::query()->count())->toBe(1)
        ->and($family->familyTrees()->count())->toBe(1)
        ->and($family->memberships()->count())->toBe(3);
});

test('deleting from dedicated pages returns to a live list and completed invitations stay immutable', function () {
    [$super, $admin, $family] = adminDemo();
    $tree = $family->primaryTree;
    $person = $tree->people()->create(['first_name_ar' => 'مؤقت', 'full_name_ar' => 'مؤقت']);
    $this->actingAs($admin)->from(route('management.records.edit', [$family->slug, 'people', $person->id]))
        ->delete(route('management.people.destroy', [$family->slug, $person->id]))
        ->assertRedirect(route('management.sections.index', [$family->slug, 'people']));
    $this->get(route('management.sections.index', [$family->slug, 'people']))->assertOk();
    Notification::fake();
    $this->post(route('invitations.store', $family->slug), ['email' => 'completed@example.test', 'role' => 'reader']);
    $invitation = FamilyInvitation::query()->sole();
    $invitation->update(['accepted_at' => now()]);
    $this->put(route('invitations.update', [$family->slug, $invitation->id]), ['role' => 'admin'])->assertSessionHasErrors('invitation');
    $this->delete(route('invitations.cancel', [$family->slug, $invitation->id]))->assertSessionHasErrors('invitation');
    expect($invitation->fresh()->role)->toBe('reader')->and($invitation->fresh()->cancelled_at)->toBeNull();
});
