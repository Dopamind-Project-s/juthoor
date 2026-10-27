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
        $this->call(RolePermissionSeeder::class);
        $this->call(PersonAttributeSeeder::class);
        if (app()->environment(['local', 'testing'])) {
            $this->call(AlZoubiFamilyTreeSeeder::class);
            $this->call(SiteUsersSeeder::class);
        }
    }
}
