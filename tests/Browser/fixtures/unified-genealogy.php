<?php

use App\Models\Family;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing'])) {
    throw new RuntimeException('Browser fixtures require local/testing.');
}
$tree = Family::where('slug', 'al-zoubi')->sole()->familyTrees()->where('slug', 'main')->sole();
$fixture = [];
foreach (['ROOT-001', 'ROOT-049', 'ROOT-076', 'QW-0003', 'QW-0031', 'AZ-BAKR-FOUNDER'] as $key) {
    $fixture[$key] = $tree->people()->where('canonical_key', $key)->sole()->id;
}
User::where('username', 'superadmin')->where('email', 'superadmin@juthoor.test')->sole();
$fixture['count'] = $tree->people()->count();
$fixture['source'] = $tree->family->sources()->where('title', 'like', '%dataset_v4%')->firstOrFail()->id;
$path = $argv[1] ?? '/tmp/juthoor-browser/fixture.json';
if (! is_dir(dirname($path))) {
    mkdir(dirname($path), 0700, true);
}
file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Read-only browser fixture: '.$path.PHP_EOL;
