<?php

namespace Tests\Fixtures;

use Database\Seeders\AlZoubiGenealogySeeder;
use Database\Seeders\DevelopmentAdminSeeder;
use Database\Seeders\PersonAttributeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Seeder;

/** Frozen bootstrap for regression tests of historical import/audit compatibility. */
class LegacyGenealogySeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AlZoubiGenealogySeeder::class, PersonAttributeSeeder::class, RolePermissionSeeder::class, DevelopmentAdminSeeder::class]);
    }
}
