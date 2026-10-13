<?php

use App\Models\Family;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_contains((string) config('database.connections.sqlite.database'), 'juthoor-browser')) {
    throw new RuntimeException('Browser fixtures require an isolated testing SQLite database named juthoor-browser.');
}
Artisan::call('migrate:fresh', ['--force' => true]);
$super = User::factory()->create(['name' => 'مشرف اصطناعي', 'email' => 'browser-admin@example.test', 'password' => 'browser-test-password']);
$super->forceFill(['is_super_admin' => true])->save();
$family = Family::query()->create(['name_ar' => 'عائلة الاختبار الاصطناعية', 'slug' => 'synthetic', 'is_active' => true]);
$tree = $family->familyTrees()->create(['name_ar' => 'الشجرة الاصطناعية الكبيرة', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
$person = fn ($tree, $name) => $tree->people()->create(['first_name_ar' => explode(' ', $name)[0], 'full_name_ar' => $name, 'is_living' => false, 'status' => 'historical', 'verification_status' => 'verified']);
$edge = fn ($tree, $child, $parent) => $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $parent->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
$root = $person($tree, 'الجذر الاصطناعي');
$tree->update(['root_person_id' => $root->id]);
$small = $person($tree, 'مؤسس الفرع الصغير');
$edge($tree, $small, $root);
$wide = $person($tree, 'مؤسس الفرع الواسع');
$edge($tree, $wide, $root);
$parents = [$small];
for ($generation = 1; $generation <= 3; $generation++) {
    $next = [];
    foreach ($parents as $parent) {
        for ($number = 1; $number <= 3; $number++) {
            $child = $person($tree, "شخص اصطناعي {$generation} {$parent->id} {$number}");
            $edge($tree, $child, $parent);
            $next[] = $child;
        }
    }
    $parents = $next;
}
for ($number = 1; $number <= 360; $number++) {
    $name = $number === 360 ? 'شخص بعيد غير محمل ذو اسم عربي طويل عبد الرحمن محمد أحمد إبراهيم محمود الاختبار الاصطناعي' : "عضو اصطناعي واسع {$number}";
    $child = $person($tree, $name);
    $edge($tree, $child, $wide);
}
foreach ([[$small, 'small', 'الفرع الصغير'], [$wide, 'wide', 'الفرع الواسع']] as [$founder, $slug, $name]) {
    $tree->branches()->create(['root_person_id' => $founder->id, 'name_ar' => $name, 'slug' => $slug, 'branch_type' => 'family_group', 'membership_mode' => 'descendants', 'is_active' => true, 'verification_status' => 'verified']);
}
$second = $family->familyTrees()->create(['name_ar' => 'شجرة مستقلة ثانية', 'slug' => 'second', 'is_primary' => false, 'is_active' => true]);
$secondRoot = $person($second, 'جذر مستقل للشجرة الثانية');
$second->update(['root_person_id' => $secondRoot->id]);
$private = $family->familyTrees()->create(['name_ar' => 'شجرة خاصة قيد البناء', 'slug' => 'private', 'is_primary' => false, 'is_active' => true]);
$privateRoot = $person($private, 'جذر خاص قيد المراجعة');
$privateRoot->update(['status' => 'active', 'is_living' => true, 'verification_status' => 'pending_review']);
$private->update(['root_person_id' => $privateRoot->id]);
foreach (['reader', 'editor', 'admin'] as $role) {
    $member = User::factory()->create(['name' => 'عضو اختبار '.$role, 'email' => 'workflow-'.$role.'@example.test', 'password' => 'browser-test-password']);
    $family->memberships()->create(['user_id' => $member->id, 'role' => $role]);
}
$otherFamily = Family::query()->create(['name_ar' => 'عائلة اختبار مستقلة ثانية', 'slug' => 'workflow-other', 'is_active' => true]);
$otherTree = $otherFamily->familyTrees()->create(['name_ar' => 'شجرة مستقلة', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
$otherPerson = $person($otherTree, 'شخص من العائلة الثانية');
$otherTree->update(['root_person_id' => $otherPerson->id]);
echo json_encode(['root' => $root->id, 'small' => $small->id, 'wide' => $wide->id, 'far' => $child->id, 'total' => $tree->people()->count()], JSON_THROW_ON_ERROR);
