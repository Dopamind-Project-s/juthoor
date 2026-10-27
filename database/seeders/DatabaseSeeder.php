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
            $this->call(AlZoubiFamilyTreeSeeder::class);
        
        if (app()->environment(['local', 'testing'])) {
            $this->call(SiteUsersSeeder::class);
        }
    }
}
