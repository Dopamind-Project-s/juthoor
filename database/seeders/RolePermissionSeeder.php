<?php

namespace Database\Seeders;

use App\Console\Commands\SyncRoleCatalog;
use App\Services\Access\RoleCatalogSynchronizer;
use Illuminate\Database\Seeder;

/**
 * Installs the permission catalog and functional role templates. Safe to rerun in any environment: it never creates
 * accounts, never touches user assignments, and never widens an existing role (see RoleCatalogSynchronizer).
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $report = app(RoleCatalogSynchronizer::class)->sync();
        if ($this->command) {
            SyncRoleCatalog::render($report, fn (string $line) => $this->command->info($line), fn (string $line) => $this->command->warn($line));
        }
    }
}
