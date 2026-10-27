<?php

use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Community\MemberDirectory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing'])) {
    throw new RuntimeException('Local/testing fixture only.');
}
if (config('database.connections.sqlite.database') !== '/tmp/juthoor-sanad-browser.sqlite') {
    throw new RuntimeException('Disposable database required.');
}
$app->make(RolePermissionSeeder::class)->run();
$family = Family::create(['is_active' => true, 'slug' => 'sanad-demo', 'name_ar' => 'عائلة السند التجريبية', 'location' => 'السلط', 'short_description' => 'بيانات اصطناعية لاختبار دليل السند']);
$family->familyTrees()->create(['slug' => 'main', 'name_ar' => 'شجرة الاختبار', 'is_primary' => true, 'is_active' => true]);
$other = Family::create(['is_active' => true, 'slug' => 'sanad-other', 'name_ar' => 'العائلة الأخرى']);
$other->familyTrees()->create(['slug' => 'main', 'name_ar' => 'الشجرة الأخرى', 'is_primary' => true, 'is_active' => true]);
$clan = CommunityEntity::create(['family_id' => $family->id, 'name_ar' => 'عشيرة السند', 'slug' => 'sanad-clan', 'entity_type' => 'clan', 'publication_status' => 'published']);
$fakhd = CommunityEntity::create(['family_id' => $family->id, 'parent_id' => $clan->id, 'name_ar' => 'فخذ التعاون', 'slug' => 'cooperation', 'entity_type' => 'fakhd', 'publication_status' => 'published']);
foreach (['admin', 'member', 'sender', 'new', 'outsider', 'moderator'] as $role) {
    $u = User::factory()->create(['name' => 'حساب تجريبي '.$role, 'username' => 'sanad-'.$role, 'email' => 'sanad-'.$role.'@example.test', 'password' => Hash::make('password'), 'email_verified_at' => now(), 'is_active' => true]);
    FamilyMembership::create(['user_id' => $u->id, 'family_id' => $role === 'outsider' ? $other->id : $family->id, 'role' => $role === 'admin' ? 'admin' : 'reader']);
    $users[$role] = $u;
}
$moderatorMembership = FamilyMembership::query()->where('user_id', $users['moderator']->id)->where('family_id', $family->id)->sole();
$moderatorMembership->roleAssignments()->create(['role_id' => Role::query()->where('key', 'directory-moderator')->sole()->id]);
$dir = app(MemberDirectory::class);
foreach (['أحمد عبد الرحمن السند', 'سارة محمد السند', 'محمد إبراهيم السند'] as $i => $name) {
    $u = $i === 0 ? $users['member'] : User::factory()->create(['name' => $name, 'username' => 'sanad-profile-'.$i, 'email' => 'sanad-profile-'.$i.'@example.test', 'password' => Hash::make('password'), 'email_verified_at' => now(), 'is_active' => true]);
    if ($i) {
        FamilyMembership::create(['user_id' => $u->id, 'family_id' => $family->id, 'role' => 'reader']);
    }
    $p = $dir->save($u, $family, ['display_name' => $name, 'entity_id' => $fakhd->id, 'profession' => $i === 1 ? 'طبيبة' : 'مهندس', 'specialization' => $i === 1 ? 'طب الأسرة' : 'تصميم إنشائي', 'sector' => $i === 1 ? 'health' : 'engineering', 'skills' => ['إدارة مشاريع', 'تدريب'], 'services' => 'استشارات مهنية', 'business_category' => 'consulting', 'business_name' => 'أعمال تجريبية', 'city' => 'السلط', 'collaboration' => 'تطوع وتوجيه مهني', 'contact_phone' => '+962799999999', 'organization' => 'جهة عمل سرية', 'audience' => 'public'], ['profession' => 'public', 'sector' => 'public', 'skills' => 'public', 'business' => 'public', 'city' => 'public', 'collaboration' => 'public', 'affiliation' => 'public', 'messages' => 'members', 'contact_phone' => 'hidden', 'organization' => 'hidden'], true, null, false);
    $dir->reviewAffiliation($users['admin'], $p, 'verified', 'انتماء اصطناعي للاختبار');
    $profiles[] = $p->id;
}
$private = $dir->save($users['sender'], $family, ['display_name' => 'عضو للعائلة فقط', 'audience' => 'members'], [], true, null, false);
$report = $dir->report($users['sender'], MemberProfile::find($profiles[0]), 'incorrect', 'بلاغ اصطناعي');
file_put_contents('/tmp/juthoor-sanad-fixture.json', json_encode(['family' => $family->slug, 'fakhd' => $fakhd->id, 'profiles' => $profiles, 'report' => $report->id]));
