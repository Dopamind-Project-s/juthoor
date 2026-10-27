<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AlZoubiGenealogySeeder::class);
        $this->call(PersonAttributeSeeder::class);
        // Safe in every environment: creates missing catalog entries only and never touches assignments or
        // customizations. Runs after the genealogy seeders so a refused genealogy run leaves nothing behind.
        $this->call(RolePermissionSeeder::class);
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevelopmentAdminSeeder::class);
        }
    }
}
