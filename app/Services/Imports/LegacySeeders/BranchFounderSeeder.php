<?php

namespace App\Services\Imports\LegacySeeders;

use App\Models\Family;
use App\Services\Imports\BranchFounderResolutionImporter;
use Illuminate\Database\Seeder;

class BranchFounderSeeder extends Seeder
{
    public function run(): void
    {
        $tree = Family::query()->where('slug', FamilySeeder::SLUG)->sole()
            ->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->sole();
        $statistics = app(BranchFounderResolutionImporter::class)->import($tree);

        $this->command?->info(sprintf(
            'Branch founders: people_created=%d relationships_created=%d branches_activated=%d unresolved=%d',
            $statistics['people_created'],
            $statistics['relationships_created'],
            $statistics['branches_activated'],
            $statistics['unresolved'],
        ));
        foreach ($statistics['preserved'] as $preserved) {
            $this->command?->warn('Preserved: '.$preserved);
        }
    }
}
