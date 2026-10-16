<?php

use App\Models\Family;
use App\Models\User;
use Database\Seeders\AlZoubiResearchContextSeeder;
use Database\Seeders\AlZoubiSharedAncestrySeeder;
use Database\Seeders\DevelopmentAdminSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.sqlite.database');
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || ! str_starts_with($database, '/tmp/juthoor-shared-browser-') || file_exists($database)) {
    throw new RuntimeException('Requires a NEW /tmp/juthoor-shared-browser-*.sqlite database and APP_ENV=testing.');
}
touch($database);
Artisan::call('migrate', ['--force' => true]);
foreach ([AlZoubiSharedAncestrySeeder::class, AlZoubiResearchContextSeeder::class, DevelopmentAdminSeeder::class] as $seeder) {
    app($seeder)->run();
}
$admin = User::query()->where('username', 'familyadmin')->sole();
foreach (['synthetic-one', 'synthetic-two', 'unrelated'] as $slug) {
    $family = Family::query()->create(['slug' => $slug, 'name_ar' => 'عائلة تجريبية '.$slug, 'is_active' => true]);
    $tree = $family->familyTrees()->create(['slug' => 'main', 'name_ar' => 'شجرة اختبار اصطناعية', 'is_primary' => true, 'is_active' => true]);
    $family->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
    $previous = null;
    $root = null;
    $founder = null;
    for ($i = 0; $i < ($slug === 'synthetic-one' ? 180 : 2); $i++) {
        $person = $tree->people()->create(['first_name_ar' => 'شخص', 'full_name_ar' => $i ? 'شخص اصطناعي '.$i : 'آدم تجريبي متشابه', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
        if ($previous) {
            $person->outgoingRelationships()->create(['family_tree_id' => $tree->id, 'related_person_id' => $previous->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
        } else {
            $tree->update(['root_person_id' => $person->id]);
            $root = $person;
        }
        if ($i === 100) {
            $founder = $person;
        }
        $previous = $person;
    }
    if ($founder) {
        $tree->branches()->create(['name_ar' => 'فرع اختباري عميق', 'slug' => 'deep', 'root_person_id' => $founder->id, 'is_active' => true, 'verification_status' => 'verified']);
        $side = $tree->people()->create(['first_name_ar' => 'جانبي', 'full_name_ar' => 'فرع جانبي للاختبار', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
        $side->outgoingRelationships()->create(['family_tree_id' => $tree->id, 'related_person_id' => $root->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    }
}
echo "Isolated browser fixture ready.\n";
