<?php

use App\Models\CommunityEntity;
use App\Models\CommunityEvent;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\NewsPost;
use App\Models\Role;
use App\Models\User;
use App\Services\Community\CommunityContentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing']) || config('database.connections.sqlite.database') !== '/tmp/juthoor-community-p08.sqlite') {
    throw new RuntimeException('The publishing fixture requires its dedicated disposable SQLite database.');
}
app(RolePermissionSeeder::class)->run();
$family = Family::create(['name_ar' => 'عائلة المجتمع التجريبية — محتوى اصطناعي', 'slug' => 'community-p08', 'is_active' => true]);
$family->familyTrees()->create(['name_ar' => 'شجرة تجريبية', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
$root = CommunityEntity::create(['family_id' => $family->id, 'name_ar' => 'مجتمع العائلة التجريبي', 'slug' => 'community', 'entity_type' => 'clan', 'publication_status' => 'published']);
$fakhd = CommunityEntity::create(['family_id' => $family->id, 'parent_id' => $root->id, 'name_ar' => 'فخذ التعاون التجريبي', 'slug' => 'cooperation', 'entity_type' => 'fakhd', 'publication_status' => 'published']);
$sibling = CommunityEntity::create(['family_id' => $family->id, 'parent_id' => $root->id, 'name_ar' => 'فخذ آخر', 'slug' => 'sibling', 'entity_type' => 'fakhd', 'publication_status' => 'published']);
foreach (['admin', 'editor', 'publisher', 'reader'] as $role) {
    $user = User::factory()->create(['name' => 'حساب تحرير تجريبي '.$role, 'username' => 'p08-'.$role, 'email' => 'p08-'.$role.'@example.test', 'password' => 'password']);
    $membership = FamilyMembership::create(['family_id' => $family->id, 'user_id' => $user->id, 'role' => $role === 'admin' ? 'admin' : 'reader']);
    if (in_array($role, ['editor', 'publisher'], true)) {
        $membership->roleAssignments()->create(['role_id' => Role::where('key', 'news-'.$role)->sole()->id]);
    }
    $users[$role] = $user->fresh();
}
$service = app(CommunityContentService::class);
$save = function ($owner, $record, array $fields, array $extra = []) use ($service, $users) {
    return $service->save($users['admin'], $owner, $record, $fields, ['publication_status' => 'published', 'verification_status' => 'verified', 'audience' => 'public', 'subjects' => [$owner->id], 'audiences' => [], ...$extra]);
};
foreach ([['news', 'إطلاق مبادرة السند — خبر اصطناعي'], ['announcement', 'دعوة إلى لقاء أبناء العائلة — إعلان تجريبي'], ['initiative', 'مبادرة تطوعية لدعم التعليم — محتوى اصطناعي'], ['congratulation', 'تهنئة تخرج — مثال تحريري'], ['death', 'نعي تجريبي لا يخص شخصًا حقيقيًا']] as $i => [$category,$title]) {
    $post = $save($fakhd, new NewsPost, ['category' => $category, 'title' => $title, 'summary' => 'محتوى تطوير اصطناعي لعرض تجربة الأخبار، وليس خبرًا حقيقيًا.', 'body' => "هذا النص اصطناعي أُنشئ للتحقق من تجربة النشر.\n\nتُعرض الأخبار المعتمدة ضمن نطاق العائلة والفخذ، مع احترام الموافقات والخصوصية.", 'publish_at' => now()->subHours($i + 1), 'is_featured' => $i === 0, 'deceased_name' => $category === 'death' ? 'اسم اصطناعي' : null, 'family_consent' => $category === 'death'], ['sensitive_confirmation' => true]);
    $posts[$category === 'news' && $i === 3 ? 'congratulation' : $category] = $post->id;
}
$save($sibling, new NewsPost, ['category' => 'news', 'title' => 'خبر الفخذ الشقيق — مثال عزل', 'body' => 'محتوى اصطناعي لا يظهر في فخذ التعاون.']);
$save($fakhd, new NewsPost, ['category' => 'news', 'title' => 'خبر سري لا يظهر للعامة', 'body' => 'مسودة لا تُنشر'], ['publication_status' => 'draft']);
$save($fakhd, new NewsPost, ['category' => 'news', 'title' => 'خبر مجدول لا يظهر الآن', 'body' => 'نشر مستقبلي', 'publish_at' => now()->addDays(7)]);
foreach (['wedding', 'graduation', 'achievement', 'meeting'] as $i => $category) {
    $event = $save($fakhd, new CommunityEvent, ['category' => $category, 'title' => CommunityEvent::CATEGORIES[$category].' — مناسبة اصطناعية', 'description' => 'وصف تجريبي لمناسبة غير حقيقية، للتحقق من الوقت والمكان والعرض.', 'organizer' => 'فريق تطوعي تجريبي', 'starts_at' => now()->addDays($i + 1), 'ends_at' => now()->addDays($i + 1)->addHours(2), 'timezone' => 'Asia/Amman', 'location_name' => 'مكان تجريبي غير حقيقي']);
    $events[$category] = $event->id;
}
file_put_contents('/tmp/juthoor-community-p08-fixture.json', json_encode(['family' => $family->slug, 'root' => $root->slug, 'fakhd' => $fakhd->slug, 'entity' => $fakhd->id, 'posts' => $posts, 'events' => $events], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
