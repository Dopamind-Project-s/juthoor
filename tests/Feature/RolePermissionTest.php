<?php

use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyRoleAssignment;
use App\Models\NewsPost;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\FamilyPermissions;
use App\Services\Access\PermissionCatalog;
use App\Services\Access\RoleCatalogSynchronizer;
use App\Services\ChangeReviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->family = Family::query()->create(['name_ar' => 'عائلة الأدوار', 'slug' => 'roles-family', 'is_active' => true]);
    $this->tree = $this->family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $this->other = Family::query()->create(['name_ar' => 'عائلة أخرى', 'slug' => 'other-family', 'is_active' => true]);
    $this->otherTree = $this->other->familyTrees()->create(['name_ar' => 'شجرة أخرى', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $this->entity = CommunityEntity::query()->create(['family_id' => $this->family->id, 'entity_type' => 'clan', 'name_ar' => 'العشيرة', 'slug' => 'clan', 'publication_status' => 'published']);
    $this->member = function (string $role, array $functionalRoles = [], ?Family $family = null): User {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $membership = FamilyMembership::query()->create(['family_id' => ($family ?? $this->family)->id, 'user_id' => $user->id, 'role' => $role]);
        foreach ($functionalRoles as $key) {
            $membership->functionalRoles()->attach(Role::query()->where('key', $key)->value('id'));
        }

        return $user->fresh();
    };
});

test('catalog: membership rights are nested and the admin role holds every family permission, never platform governance', function () {
    $sets = PermissionCatalog::MEMBERSHIP_PERMISSIONS;
    expect(array_diff($sets['reader'], $sets['editor']))->toBe([])
        ->and(array_diff($sets['editor'], $sets['admin']))->toBe([])
        ->and(collect($sets['admin'])->sort()->values()->all())->toBe(collect(PermissionCatalog::familyPermissionKeys())->sort()->values()->all())
        ->and(collect(PermissionCatalog::ROLES)->flatMap(fn ($role) => $role['permissions'])->diff(PermissionCatalog::familyPermissionKeys())->all())->toBe([]);
    // Every catalog permission and template is installed exactly once.
    expect(Permission::query()->count())->toBe(count(PermissionCatalog::PERMISSIONS))
        ->and(Role::query()->where('is_system', true)->count())->toBe(count(PermissionCatalog::ROLES));
});

test('functional role matrix: allow and deny per role, scoped to one family', function () {
    $matrix = [
        // [membership role, functional roles, allowed abilities, denied abilities]
        ['reader', [], ['view', 'propose'], ['review', 'publish', 'manageSources', 'merge', 'recover', 'manageMembers', 'assignRoles', 'moderateDirectory', 'reviewDeathNotices', 'update']],
        ['editor', [], ['propose', 'manageSources'], ['review', 'publish', 'merge', 'recover', 'assignRoles', 'moderateDirectory']],
        ['admin', [], ['review', 'publish', 'manageSources', 'merge', 'recover', 'manageMembers', 'assignRoles', 'moderateDirectory', 'reviewDeathNotices', 'update', 'managePrivacy'], []],
        ['reader', ['genealogy-reviewer'], ['review'], ['publish', 'manageSources', 'merge', 'recover', 'manageMembers', 'assignRoles', 'moderateDirectory', 'update']],
        ['reader', ['genealogy-editor'], ['manageSources'], ['review', 'publish', 'merge']],
        ['reader', ['genealogy-manager'], ['review', 'publish', 'manageSources', 'managePrivacy'], ['merge', 'recover', 'manageMembers', 'assignRoles', 'update']],
        ['reader', ['news-editor'], [], ['review', 'publish', 'manageSources', 'reviewDeathNotices', 'moderateDirectory']],
        ['reader', ['death-notice-reviewer'], ['reviewDeathNotices'], ['review', 'publish', 'moderateDirectory']],
        ['reader', ['directory-moderator'], ['moderateDirectory'], ['review', 'publish', 'manageMembers']],
        ['reader', ['content-manager'], [], ['review', 'publish', 'manageSources', 'reviewDeathNotices']],
    ];
    foreach ($matrix as [$membership, $roles, $allowed, $denied]) {
        $user = ($this->member)($membership, $roles);
        $label = $membership.'+'.implode(',', $roles);
        foreach ($allowed as $ability) {
            expect(Gate::forUser($user)->allows($ability, $this->family))->toBeTrue("$label should $ability");
            // Nothing leaks into another family.
            expect(Gate::forUser($user)->allows($ability, $this->other))->toBeFalse("$label must not $ability elsewhere");
        }
        foreach ($denied as $ability) {
            expect(Gate::forUser($user)->allows($ability, $this->family))->toBeFalse("$label must not $ability");
        }
    }
    // Person-level abilities follow the same permissions.
    $person = $this->tree->people()->create(['first_name_ar' => 'سالم', 'full_name_ar' => 'سالم', 'status' => 'historical', 'verification_status' => 'pending_review']);
    expect(Gate::forUser(($this->member)('reader', ['genealogy-editor']))->allows('update', $person))->toBeTrue()
        ->and(Gate::forUser(($this->member)('reader', ['genealogy-reviewer']))->allows('update', $person))->toBeFalse()
        ->and(Gate::forUser(($this->member)('reader', ['news-editor']))->allows('create', [Person::class, $this->family]))->toBeFalse();
});

test('content roles are module scoped: a news editor drafts news but not other content, and gains nothing in genealogy', function () {
    $newsEditor = ($this->member)('reader', ['news-editor']);
    $contentManager = ($this->member)('reader', ['content-manager']);
    expect(Gate::forUser($newsEditor)->allows('update', [$this->entity, 'news']))->toBeTrue()
        ->and(Gate::forUser($newsEditor)->allows('update', [$this->entity, 'events']))->toBeTrue()
        ->and(Gate::forUser($newsEditor)->allows('update', [$this->entity, 'history']))->toBeFalse()
        ->and(Gate::forUser($newsEditor)->allows('update', [$this->entity, 'media']))->toBeFalse()
        ->and(Gate::forUser($newsEditor)->allows('publish', [$this->entity, 'news']))->toBeFalse()
        ->and(Gate::forUser($contentManager)->allows('publish', [$this->entity, 'archive']))->toBeTrue()
        ->and(Gate::forUser($contentManager)->allows('publish', [$this->entity, 'news']))->toBeTrue();

    $this->actingAs($newsEditor)->post(route('management.entities.content.store', [$this->family->slug, $this->entity->id, 'news']), [
        'category' => 'news', 'title' => 'خبر تجريبي', 'body' => 'نص', 'publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public',
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect(NewsPost::query()->where('title', 'خبر تجريبي')->value('publication_status'))->toBe('draft');
    $this->actingAs($newsEditor)->get(route('management.entities.content.create', [$this->family->slug, $this->entity->id, 'history']))->assertForbidden();
    $this->actingAs($newsEditor)->get(route('management.changes.index', [$this->family->slug]))->assertOk()->assertDontSee('قبول وتطبيق');
});

test('a genealogy reviewer applies others\' proposals, never their own, and cannot edit directly', function () {
    $reviewer = ($this->member)('reader', ['genealogy-reviewer']);
    $submitter = ($this->member)('reader');
    $person = $this->tree->people()->create(['first_name_ar' => 'حامد', 'full_name_ar' => 'حامد', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $reviews = app(ChangeReviewService::class);
    $change = $reviews->submit($this->tree, $submitter, 'person', 'update', $person->id, ['full_name_ar' => 'حامد بن سالم', 'first_name_ar' => 'حامد'], 'تصحيح من وثيقة');

    $this->actingAs($reviewer)->post(route('management.changes.decide', [$this->family->slug, $change->id]), ['decision' => 'accepted', 'decision_reason' => 'مطابق للوثيقة'])->assertRedirect();
    expect($person->fresh()->full_name_ar)->toBe('حامد بن سالم')->and($change->fresh()->status)->toBe('accepted');

    $own = $reviews->submit($this->tree, $reviewer, 'person', 'update', $person->id, ['full_name_ar' => 'حامد الثاني', 'first_name_ar' => 'حامد'], 'مقترحي');
    $this->actingAs($reviewer)->post(route('management.changes.decide', [$this->family->slug, $own->id]), ['decision' => 'accepted', 'decision_reason' => 'ذاتي'])->assertSessionHasErrors();
    expect($own->fresh()->status)->toBe('pending')->and($person->fresh()->full_name_ar)->toBe('حامد بن سالم');

    // Direct edits stay with editors; the reviewer role grants no create/edit path.
    $this->actingAs($reviewer)->put(route('management.people.update', [$this->family->slug, $person->id]), ['first_name_ar' => 'x', 'full_name_ar' => 'x', 'status' => 'historical', 'verification_status' => 'verified'])->assertForbidden();
    // A news editor cannot decide genealogy proposals.
    $this->actingAs(($this->member)('reader', ['news-editor']))->post(route('management.changes.decide', [$this->family->slug, $own->id]), ['decision' => 'rejected', 'decision_reason' => 'x'])->assertForbidden();
});

test('role assignment: only role.assign holders, never their own roles, never beyond their own permissions, audited', function () {
    $admin = ($this->member)('admin');
    $target = ($this->member)('reader');
    $membership = $target->familyMemberships()->sole();
    $reviewerRole = Role::query()->where('key', 'genealogy-reviewer')->sole();
    $managerRole = Role::query()->where('key', 'genealogy-manager')->sole();

    $this->actingAs(($this->member)('editor'))->put(route('management.memberships.roles.update', [$this->family->slug, $membership->id]), ['role_ids' => [$reviewerRole->id]])->assertForbidden();
    $this->actingAs($admin)->put(route('management.memberships.roles.update', [$this->family->slug, $membership->id]), ['role_ids' => [$reviewerRole->id]])->assertRedirect()->assertSessionHasNoErrors();
    expect($target->hasFamilyPermission($this->family, 'genealogy.review'))->toBeTrue()
        ->and(FamilyRoleAssignment::query()->sole()->assigned_by)->toBe($admin->id)
        ->and(AuditLog::query()->where('action', 'roles.updated')->sole()->after['functional_roles'])->toBe(['genealogy-reviewer']);

    // A delegated coordinator (custom role) may assign roles but only within the permissions they hold.
    $coordinator = Role::query()->create(['key' => 'member-coordinator', 'scope' => 'family', 'label_ar' => 'منسق الأعضاء', 'is_system' => false, 'customized_at' => now()]);
    $coordinator->permissions()->attach(Permission::query()->whereIn('key', ['role.assign', 'family.members.manage', 'genealogy.review'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['origin' => 'manual']])->all());
    $delegate = ($this->member)('reader', ['member-coordinator']);
    $this->actingAs($delegate)->put(route('management.memberships.roles.update', [$this->family->slug, $membership->id]), ['role_ids' => [$reviewerRole->id, $managerRole->id]])->assertSessionHasErrors('role_ids');
    expect($membership->functionalRoles()->pluck('key')->all())->toBe(['genealogy-reviewer']);
    // Nobody but a super admin changes their own roles.
    $this->actingAs($delegate)->put(route('management.memberships.roles.update', [$this->family->slug, $delegate->familyMemberships()->sole()->id]), ['role_ids' => []])->assertSessionHasErrors('role_ids');
    // Another family's membership is out of reach.
    $foreign = ($this->member)('reader', [], $this->other)->familyMemberships()->sole();
    $this->actingAs($admin)->put(route('management.memberships.roles.update', [$this->family->slug, $foreign->id]), ['role_ids' => [$reviewerRole->id]])->assertNotFound();

    // Leaving the family ends the roles; rejoining does not restore them.
    $this->actingAs($admin)->delete(route('management.memberships.destroy', [$this->family->slug, $membership->id]))->assertRedirect();
    expect(FamilyRoleAssignment::query()->where('family_membership_id', $membership->id)->exists())->toBeFalse();
    FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $target->id, 'role' => 'reader']);
    expect($target->fresh()->hasFamilyPermission($this->family, 'genealogy.review'))->toBeFalse();
    // Deactivated accounts hold nothing.
    $reviewer = ($this->member)('reader', ['genealogy-reviewer']);
    $reviewer->forceFill(['is_active' => false])->save();
    expect(app(FamilyPermissions::class)->for($reviewer->fresh(), $this->family))->toBe([]);
});

test('members page shows functional roles to administrators and the matrix page lists the catalog', function () {
    $admin = ($this->member)('admin');
    ($this->member)('reader', ['news-editor']);
    $this->actingAs($admin)->get(route('management.sections.index', [$this->family->slug, 'members']))->assertOk()->assertSee('الأدوار الوظيفية')->assertSee('محرر الأخبار');
    $this->actingAs($admin)->get(route('management.roles.index', $this->family->slug))->assertOk()->assertSee('genealogy.review')->assertSee('مراجع الأنساب');
    $this->actingAs(($this->member)('reader'))->get(route('management.roles.index', $this->family->slug))->assertForbidden();
});

test('role seeder is idempotent and never resets assignments, customizations, manual grants or custom roles', function () {
    $assigned = ($this->member)('reader', ['news-editor']);
    $countsBefore = [Permission::query()->count(), Role::query()->count(), DB::table('permission_role')->count(), FamilyRoleAssignment::query()->count()];
    $auditsBefore = AuditLog::query()->count();
    $this->seed(RolePermissionSeeder::class);
    expect([Permission::query()->count(), Role::query()->count(), DB::table('permission_role')->count(), FamilyRoleAssignment::query()->count()])->toBe($countsBefore)
        ->and(AuditLog::query()->count())->toBe($auditsBefore);

    // Administrator customization: one catalog grant removed, one manual grant added.
    $reviewer = Role::query()->where('key', 'genealogy-reviewer')->sole();
    $superAdmin = User::factory()->create(['is_active' => true, 'is_super_admin' => true]);
    $this->actingAs($superAdmin)->put(route('site-admin.roles.update', $reviewer->id), [
        'label_ar' => 'مراجع الأنساب المحلي', 'permission_ids' => Permission::query()->whereIn('key', ['genealogy.review', 'source.manage'])->pluck('id')->all(),
    ])->assertRedirect();
    $manager = Role::query()->where('key', 'genealogy-manager')->sole();
    $manager->permissions()->detach(Permission::query()->where('key', 'privacy.manage')->value('id'));
    $manager->update(['customized_at' => now()]);
    $custom = Role::query()->create(['key' => 'archive-curator', 'scope' => 'family', 'label_ar' => 'أمين الأرشيف', 'is_system' => false, 'customized_at' => now()]);
    $custom->permissions()->attach(Permission::query()->where('key', 'content.edit')->value('id'), ['origin' => 'manual']);

    $report = app(RoleCatalogSynchronizer::class)->sync();
    expect($reviewer->fresh()->label_ar)->toBe('مراجع الأنساب المحلي')
        ->and($reviewer->fresh()->load('permissions')->permissionKeys())->toBe(['genealogy.review', 'source.manage'])
        ->and($manager->fresh()->load('permissions')->permissionKeys())->not->toContain('privacy.manage')
        ->and($report['roles']['customized'])->toHaveKeys(['genealogy-reviewer', 'genealogy-manager'])
        ->and($report['roles']['customized']['genealogy-manager']['missing'])->toBe(['privacy.manage'])
        ->and($custom->fresh()->load('permissions')->permissionKeys())->toBe(['content.edit'])
        ->and($report['custom_roles'])->toBe(['archive-curator'])
        ->and($assigned->fresh()->hasFamilyPermission($this->family, 'news.edit'))->toBeTrue();
});

test('catalog upgrades are reported, not granted silently; withdrawn grants are revoked and removed keys deprecated', function () {
    $editor = Role::query()->where('key', 'genealogy-editor')->sole();
    $holder = ($this->member)('reader', ['genealogy-editor']);
    // Simulate an older install that lacked a grant the current template has.
    $editor->permissions()->detach(Permission::query()->where('key', 'source.manage')->value('id'));
    // And an obsolete catalog grant plus a manual grant.
    $editor->permissions()->attach(Permission::query()->where('key', 'genealogy.publish')->value('id'), ['origin' => 'catalog']);
    $editor->permissions()->attach(Permission::query()->where('key', 'branch.manage')->value('id'), ['origin' => 'manual']);
    $legacy = Permission::query()->create(['key' => 'legacy.export', 'scope' => 'family', 'label_ar' => 'قديم', 'catalog_version' => 'old']);
    $editor->permissions()->attach($legacy->id, ['origin' => 'catalog']);

    $preview = app(RoleCatalogSynchronizer::class)->sync(dryRun: true);
    expect($preview['roles']['pending_grants']['genealogy-editor'])->toBe(['source.manage'])
        ->and($editor->fresh()->load('permissions')->permissions->pluck('key')->sort()->values()->all())->toBe(['branch.manage', 'genealogy.publish', 'legacy.export', 'person.create', 'person.edit-unpublished']);

    $report = app(RoleCatalogSynchronizer::class)->sync();
    $keys = fn () => $editor->fresh()->load('permissions')->permissionKeys();
    expect($report['roles']['pending_grants']['genealogy-editor'])->toBe(['source.manage'])
        ->and($report['roles']['revoked']['genealogy-editor'])->toBe(['genealogy.publish'])
        ->and($report['permissions']['deprecated'])->toBe(['legacy.export'])
        ->and($keys())->toBe(['branch.manage', 'person.create', 'person.edit-unpublished'])
        ->and($holder->hasFamilyPermission($this->family, 'genealogy.publish'))->toBeFalse()
        ->and($holder->hasFamilyPermission($this->family, 'legacy.export'))->toBeFalse();

    app(RoleCatalogSynchronizer::class)->sync(applyUpgrades: true);
    expect($keys())->toBe(['branch.manage', 'person.create', 'person.edit-unpublished', 'source.manage']);
    $this->artisan('rbac:sync', ['--dry-run' => true])->assertSuccessful();
});

test('platform governance is not grantable through any family role', function () {
    $superPowers = Role::query()->create(['key' => 'all-in', 'scope' => 'family', 'label_ar' => 'كل شيء', 'is_system' => false, 'customized_at' => now()]);
    $superAdmin = User::factory()->create(['is_active' => true, 'is_super_admin' => true]);
    $platform = Permission::query()->where('key', 'platform.govern')->sole();
    $this->actingAs($superAdmin)->put(route('site-admin.roles.update', $superPowers->id), ['label_ar' => 'كل شيء', 'permission_ids' => [$platform->id]])->assertSessionHasErrors('permission_ids.0');
    $superPowers->permissions()->attach($platform->id, ['origin' => 'manual']);
    $holder = ($this->member)('admin', ['all-in']);
    expect($holder->hasFamilyPermission($this->family, 'platform.govern'))->toBeFalse()
        ->and($holder->isSuperAdmin())->toBeFalse();
    $this->actingAs($holder)->get(route('site-admin.roles.index'))->assertForbidden();
    $this->actingAs($superAdmin)->get(route('site-admin.roles.index'))->assertOk()->assertSee('all-in');
});

test('death notices need the dedicated permission even for news publishers', function () {
    $publisher = ($this->member)('reader', ['news-publisher']);
    $verifier = ($this->member)('reader', ['death-notice-reviewer']);
    expect(Gate::forUser($publisher)->allows('publish', [$this->entity, 'news']))->toBeTrue()
        ->and(Gate::forUser($publisher)->allows('reviewDeathNotices', $this->family))->toBeFalse()
        ->and(Gate::forUser($verifier)->allows('reviewDeathNotices', $this->family))->toBeTrue();
    expect(ChangeRequest::query()->count())->toBe(0);
});
