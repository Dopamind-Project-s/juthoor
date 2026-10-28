<?php

use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\DirectoryReport;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\MemberProfile;
use App\Models\NewsPost;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Community\CommunityContentService;
use App\Services\Portal\PortalSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing']) || config('database.connections.sqlite.database') !== '/tmp/juthoor-admin-p09.sqlite' || Family::query()->exists()) {
    throw new RuntimeException('Requires a new empty isolated UX-P0.9 SQLite fixture database.');
}
app(RolePermissionSeeder::class)->run();
foreach (['first' => 'عشيرة التعاون التجريبية', 'second' => 'عائلة الأفق التجريبية', 'outside' => 'عائلة خارج الصلاحية'] as $slug => $name) {
    $family = Family::create(['name_ar' => $name, 'slug' => 'admin-'.$slug, 'is_active' => true]);
    $tree = $family->familyTrees()->create(['name_ar' => 'شجرة اصطناعية', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $person = $tree->people()->create(['full_name_ar' => 'شخص اصطناعي '.$slug, 'first_name_ar' => 'شخص', 'status' => 'active', 'verification_status' => 'verified', 'is_living' => false]);
    $tree->update(['root_person_id' => $person->id]);
    $entity = CommunityEntity::create(['family_id' => $family->id, 'family_tree_id' => $tree->id, 'name_ar' => 'مجتمع '.$name, 'slug' => 'community', 'entity_type' => 'clan', 'publication_status' => 'published']);
    $families[$slug] = $family;
    $trees[$slug] = $tree;
    $entities[$slug] = $entity;
    $people[$slug] = $person;
}
$child = CommunityEntity::create(['family_id' => $families['first']->id, 'parent_id' => $entities['first']->id, 'name_ar' => 'فخذ التآزر الاصطناعي', 'slug' => 'cooperation', 'entity_type' => 'fakhd', 'publication_status' => 'published']);
$sibling = CommunityEntity::create(['family_id' => $families['first']->id, 'parent_id' => $entities['first']->id, 'name_ar' => 'كيان شقيق خارج التحرير', 'slug' => 'sibling', 'entity_type' => 'fakhd', 'publication_status' => 'draft']);
foreach (['super', 'admin', 'genealogy-reviewer', 'genealogy-editor', 'news-editor', 'news-publisher', 'directory-moderator', 'entity-editor', 'reader'] as $role) {
    $user = User::factory()->create(['name' => 'حساب اصطناعي '.$role, 'username' => 'p09-'.$role, 'email' => 'p09-'.$role.'@example.test', 'password' => 'password']);
    if ($role === 'super') {
        $user->forceFill(['is_super_admin' => true])->save();
    } else {
        $membership = FamilyMembership::create(['family_id' => $families['first']->id, 'user_id' => $user->id, 'role' => $role === 'admin' ? 'admin' : 'reader']);
        if (in_array($role, ['genealogy-reviewer', 'genealogy-editor', 'news-editor', 'news-publisher', 'directory-moderator'])) {
            $membership->roleAssignments()->create(['role_id' => Role::where('key', $role)->sole()->id]);
        }
        if ($role === 'entity-editor') {
            CommunityEntityManager::create(['community_entity_id' => $child->id, 'user_id' => $user->id, 'role' => 'editor']);
        }
        if ($role === 'admin') {
            FamilyMembership::create(['family_id' => $families['second']->id, 'user_id' => $user->id, 'role' => 'admin']);
        }
    }
    $users[$role] = $user->fresh();
}
foreach (['first', 'second'] as $slug) {
    app(CommunityContentService::class)->save($users['super'], $entities[$slug], new NewsPost, ['title' => 'خبر اصطناعي '.$slug, 'category' => 'news', 'body' => 'نص تطوير لا يمثل خبرًا حقيقيًا.'], ['publication_status' => 'submitted', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [$entities[$slug]->id], 'audiences' => []]);
    AuditLog::create(['family_id' => $families[$slug]->id, 'user_id' => $users['admin']->id, 'auditable_type' => Person::class, 'auditable_id' => $people[$slug]->id, 'action' => 'created', 'after' => ['full_name_ar' => $people[$slug]->full_name_ar]]);
}
ChangeRequest::create(['family_tree_id' => $trees['first']->id, 'submitted_by' => $users['reader']->id, 'target_type' => 'person', 'operation' => 'create', 'payload' => ['full_name_ar' => 'مقترح اصطناعي', 'first_name_ar' => 'مقترح'], 'reason' => 'مراجعة اصطناعية لتجربة الإدارة', 'status' => 'pending']);
$profile = new MemberProfile(['display_name' => 'ملف اختياري اصطناعي', 'contact_phone' => 'PRIVATE-CONTACT-P09', 'field_visibility' => ['contact_phone' => 'hidden']]);
$profile->forceFill(['user_id' => $users['reader']->id, 'family_id' => $families['first']->id, 'entity_id' => $child->id, 'is_listed' => true, 'affiliation_status' => 'pending'])->save();
DirectoryReport::create(['member_profile_id' => $profile->id, 'reporter_id' => $users['admin']->id, 'reason' => 'incorrect', 'details' => 'بلاغ اصطناعي للمراجعة']);
$settings = app(PortalSettings::class);
foreach (['first' => 'heritage', 'second' => 'maroon'] as $slug => $accent) {
    $brand = $settings->defaults($families[$slug]);
    $brand['accent'] = $accent;
    $families[$slug]->portalSetting()->create(['draft' => $brand, 'published' => $brand, 'published_at' => now(), 'published_by' => $users['super']->id]);
}
file_put_contents('/tmp/juthoor-admin-p09-fixture.json', json_encode(['families' => array_map(fn ($f) => $f->slug, $families), 'entity' => $entities['first']->id, 'child' => $child->id, 'sibling' => $sibling->id, 'person' => $people['first']->id], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
