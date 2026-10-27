<?php

use App\Models\Branch;
use App\Models\Family;
use App\Models\Person;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing']) || config('database.connections.sqlite.database') !== '/tmp/juthoor-tree-p07.sqlite') {
    throw new RuntimeException('UX-P0.7 fixtures require the dedicated disposable SQLite database.');
}
$family = Family::query()->create(['name_ar' => 'مثال تخطيط اصطناعي — ليس نسبًا حقيقيًا', 'slug' => 'layout-p07', 'is_active' => true]);
$tree = $family->familyTrees()->create(['name_ar' => 'مثال الأجيال 5 و7 و9', 'slug' => 'main', 'is_active' => true, 'is_primary' => true]);
$people = [];
$add = function (string $key, ?string $father, array $extra = []) use ($tree, &$people): Person {
    $person = $tree->people()->create(['first_name_ar' => $key, 'full_name_ar' => 'شخص اصطناعي '.$key, 'status' => 'historical', 'is_living' => false, 'verification_status' => 'verified', ...$extra]);
    if ($father !== null) {
        $tree->personRelationships()->create(['person_id' => $person->id, 'related_person_id' => $people[$father]->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    }

    return $people[$key] = $person;
};
$add('1', null);
for ($i = 2; $i <= 10; $i++) {
    $add((string) $i, (string) ($i - 1));
}
$add('جانبي', '1');
for ($i = 1; $i <= 15; $i++) {
    $add('أخ-'.$i, 'جانبي');
}
$add('تفصيل-5', '5');
$add('خاص', '9', ['verification_status' => 'pending_review']);
$tree->update(['root_person_id' => $people['1']->id]);
$branch = function (string $slug, string $person, string $type, ?Branch $parent = null) use ($tree, $people): Branch {
    return $tree->branches()->create(['slug' => $slug, 'name_ar' => 'مثال '.$slug, 'root_person_id' => $people[$person]->id, 'branch_type' => $type, 'parent_branch_id' => $parent?->id, 'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS, 'is_active' => true, 'verification_status' => 'verified']);
};
$branch('generation-five', '5', 'fakhd');
$main = $branch('generation-seven', '7', 'fakhd');
$branch('generation-nine', '9', 'sub_fakhd', $main);
$actual = Family::query()->where('slug', 'al-zoubi')->sole()->familyTrees()->where('slug', 'main')->sole();
$fixture = ['synthetic' => collect($people)->map->id->all(), 'actual' => $actual->people()->whereIn('canonical_key', ['ROOT-001', 'ROOT-020', 'ROOT-049', 'QW-0003', 'QW-0031', 'AZ-BAKR-FOUNDER'])->pluck('id', 'canonical_key')->all(), 'count' => $actual->people()->count()];
file_put_contents('/tmp/juthoor-tree-p07-fixture.json', json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
