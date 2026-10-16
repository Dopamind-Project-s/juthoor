<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AlZoubiGenealogySeeder::class);
        $this->call(PersonAttributeSeeder::class);
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevelopmentAdminSeeder::class);
        }
    }
}
