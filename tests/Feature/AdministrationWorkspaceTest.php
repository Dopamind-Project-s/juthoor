<?php

use App\Models\ChangeRequest;
use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\EntityNarrative;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\NewsPost;
use App\Models\Role;
use App\Models\User;
use App\Services\Community\CommunityContentService;
use App\Services\Portal\PortalSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->family = Family::create(['name_ar' => 'عائلة الإدارة', 'slug' => 'workspace', 'is_active' => true]);
    $this->tree = $this->family->familyTrees()->create(['name_ar' => 'الشجرة الرئيسية', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $this->entity = CommunityEntity::create(['family_id' => $this->family->id, 'name_ar' => 'مجتمع العائلة', 'slug' => 'community', 'entity_type' => 'clan', 'publication_status' => 'published']);
    $this->admin = User::factory()->create()->fresh();
    FamilyMembership::create(['family_id' => $this->family->id, 'user_id' => $this->admin->id, 'role' => 'admin']);
});

function workspaceUser(Family $family, string $role): User
{
    $user = User::factory()->create()->fresh();
    $membership = FamilyMembership::create(['family_id' => $family->id, 'user_id' => $user->id, 'role' => 'reader']);
    $membership->roleAssignments()->create(['role_id' => Role::where('key', $role)->sole()->id]);

    return $user;
}

test('platform and family administration use distinct frames and published approved branding', function () {
    $settings = app(PortalSettings::class)->defaults($this->family);
    $this->family->portalSetting()->create(['published' => [...$settings, 'accent' => 'maroon'], 'draft' => [...$settings, 'accent' => 'night', 'display_name' => 'مسودة هوية لا تنشر']]);
    $super = User::factory()->create(['is_super_admin' => true])->fresh();
    $this->actingAs($super)->get(route('site-admin.dashboard'))->assertOk()->assertSee('data-admin-environment="platform"', false)->assertSee('إدارة منصة جذور');
    $this->get(route('management.families.dashboard', 'workspace'))->assertOk()->assertSee('data-admin-environment="family"', false)
        ->assertSee('--workspace-primary:#5e2430', false)->assertDontSee('مسودة هوية لا تنشر');
});

test('specialized workspaces expose actual workflows without unrelated management actions', function (string $role, string $expected, string $absent) {
    $user = workspaceUser($this->family, $role);
    $response = $this->actingAs($user)->get(route('management.families.dashboard', 'workspace'));
    $response->assertOk()->assertSee($expected)->assertDontSee($absent)
        ->assertDontSee(route('management.records.create', ['workspace', 'people', 'tree' => 'main']), false)
        ->assertDontSee(route('management.roles.index', ['workspace', 'tree' => 'main']), false);
})->with([
    ['news-editor', 'إضافة خبر', 'بلاغات الدليل ومراجعة الانتساب'],
    ['directory-moderator', 'بلاغات الدليل ومراجعة الانتساب', 'إضافة خبر'],
    ['genealogy-reviewer', 'مراجعة الأنساب', 'إضافة خبر'],
]);

test('family switcher lists only authorized families and does not transfer tree context', function () {
    $other = Family::create(['name_ar' => 'عائلة ثانية', 'slug' => 'second', 'is_active' => true]);
    $other->familyTrees()->create(['name_ar' => 'شجرة ثانية', 'slug' => 'second-tree', 'is_primary' => true]);
    FamilyMembership::create(['family_id' => $other->id, 'user_id' => $this->admin->id, 'role' => 'admin']);
    $outside = Family::create(['name_ar' => 'عائلة غير مصرح بها', 'slug' => 'outside', 'is_active' => true]);
    $this->actingAs($this->admin)->get(route('management.families.dashboard', 'workspace'))->assertOk()->assertSee('عائلة ثانية')->assertDontSee($outside->name_ar)
        ->assertDontSee(route('management.families.dashboard', 'second').'?tree', false);
    $this->get(route('management.families.dashboard', 'outside'))->assertForbidden();
    $this->get(route('management.families.dashboard', ['workspace', 'tree' => 'second-tree']))->assertNotFound();
});

test('entity editors cannot discover unmanageable draft siblings through the workspace', function () {
    $child = CommunityEntity::create(['family_id' => $this->family->id, 'parent_id' => $this->entity->id, 'name_ar' => 'فخذ مخول', 'slug' => 'allowed', 'entity_type' => 'fakhd', 'publication_status' => 'published']);
    $sibling = CommunityEntity::create(['family_id' => $this->family->id, 'parent_id' => $this->entity->id, 'name_ar' => 'مسودة شقيقة خاصة', 'slug' => 'secret', 'entity_type' => 'fakhd', 'publication_status' => 'draft']);
    $user = User::factory()->create()->fresh();
    FamilyMembership::create(['family_id' => $this->family->id, 'user_id' => $user->id, 'role' => 'reader']);
    CommunityEntityManager::create(['community_entity_id' => $child->id, 'user_id' => $user->id, 'role' => 'editor']);
    $this->actingAs($user)->get(route('management.entities.index', 'workspace'))->assertOk()->assertSee('فخذ مخول')->assertDontSee($sibling->name_ar);
    $this->get(route('management.entities.edit', ['workspace', $sibling->id]))->assertForbidden();
    $this->get(route('management.families.dashboard', 'workspace'))->assertOk()->assertSee(route('management.entities.content.create', ['workspace', $child->id, 'news']), false);
});

test('review filters retain family scope and own requests never become approval tasks', function () {
    $reviewer = workspaceUser($this->family, 'genealogy-reviewer');
    foreach ([[$reviewer, 'طلبي الذاتي'], [$this->admin, 'مقترح الآخرين']] as [$user, $reason]) {
        ChangeRequest::create(['family_tree_id' => $this->tree->id, 'submitted_by' => $user->id, 'target_type' => 'person', 'operation' => 'create', 'payload' => ['full_name_ar' => 'شخص اصطناعي'], 'reason' => $reason, 'status' => 'pending']);
    }
    $this->actingAs($reviewer)->get(route('management.families.dashboard', 'workspace'))->assertOk()->assertViewHas('reviewCount', 1);
    $this->get(route('management.changes.index', ['workspace', 'mine' => 1, 'kind' => 'person']))->assertOk()->assertSee('طلبي الذاتي')->assertDontSee('مقترح الآخرين');
    $this->getJson(route('management.changes.index', ['workspace', 'kind' => 'invented']))->assertUnprocessable();
});

test('disabled management tools disappear without bypassing backend restrictions', function () {
    DB::table('maintenance_tool_settings')->updateOrInsert(['name' => 'genealogy_administration'], ['enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->admin)->get(route('management.families.dashboard', 'workspace'))->assertOk()
        ->assertDontSee(route('management.records.create', ['workspace', 'people', 'tree' => 'main']), false);
    $this->get(route('management.sections.index', ['workspace', 'people']))->assertForbidden();
});

test('news content management paginates filters and hides unrelated module links', function () {
    $user = workspaceUser($this->family, 'news-editor');
    foreach (range(1, 23) as $number) {
        app(CommunityContentService::class)->save($user, $this->entity, new NewsPost, ['title' => 'خبر '.$number, 'category' => 'news', 'body' => 'نص اصطناعي'], ['publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [], 'audiences' => []]);
    }
    $this->actingAs($user)->get(route('management.entities.content.index', ['workspace', $this->entity->id, 'news']))->assertOk()
        ->assertViewHas('records', fn ($records) => $records->count() === 20 && $records->total() === 23)
        ->assertDontSee(route('management.entities.content.index', ['workspace', $this->entity->id, 'media']), false);
    $this->get(route('management.entities.content.index', ['workspace', $this->entity->id, 'news', 'q' => 'خبر 23']))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1);
});

test('editorial review is paginated and excludes other families and ungranted modules', function () {
    $publisher = workspaceUser($this->family, 'news-publisher');
    foreach (range(1, 23) as $number) {
        app(CommunityContentService::class)->save($this->admin, $this->entity, new NewsPost, ['title' => 'للمراجعة '.$number, 'category' => 'news', 'body' => 'نص'], ['publication_status' => 'submitted', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [], 'audiences' => []]);
    }
    EntityNarrative::create(['owner_entity_id' => $this->entity->id, 'publication_status' => 'submitted', 'section' => 'overview', 'account' => 'primary', 'title' => 'تراث خارج صلاحية الأخبار', 'body' => 'نص']);
    $other = Family::create(['name_ar' => 'أخرى', 'slug' => 'other', 'is_active' => true]);
    $entity = CommunityEntity::create(['family_id' => $other->id, 'name_ar' => 'كيان آخر', 'slug' => 'other', 'entity_type' => 'clan', 'publication_status' => 'published']);
    NewsPost::create(['owner_entity_id' => $entity->id, 'publication_status' => 'submitted', 'category' => 'news', 'title' => 'خبر خارج العائلة', 'body' => 'نص']);
    $this->actingAs($publisher)->get(route('management.entities.review', 'workspace'))->assertOk()
        ->assertViewHas('pending', fn ($pending) => $pending->count() === 20 && $pending->total() === 23)
        ->assertDontSee('تراث خارج صلاحية الأخبار')->assertDontSee('خبر خارج العائلة');
});

test('mixed news editor and entity administrator grants never widen review statistics', function () {
    $user = workspaceUser($this->family, 'news-editor');
    $child = CommunityEntity::create(['family_id' => $this->family->id, 'parent_id' => $this->entity->id, 'name_ar' => 'فخذ مخول', 'slug' => 'child', 'entity_type' => 'fakhd', 'publication_status' => 'published']);
    CommunityEntityManager::create(['community_entity_id' => $child->id, 'user_id' => $user->id, 'role' => 'admin']);
    foreach ([$this->entity, $child] as $owner) {
        app(CommunityContentService::class)->save($this->admin, $owner, new NewsPost, ['title' => 'خبر '.$owner->id, 'category' => 'news', 'body' => 'نص'], ['publication_status' => 'submitted', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [], 'audiences' => []]);
    }
    $this->actingAs($user)->get(route('management.families.dashboard', 'workspace'))->assertOk()->assertViewHas('entityContentPending', 1);
    $this->get(route('management.entities.review', 'workspace'))->assertOk()->assertViewHas('pending', fn ($pending) => $pending->total() === 1 && $pending->first()['record']->owner_entity_id === $child->id);
});
