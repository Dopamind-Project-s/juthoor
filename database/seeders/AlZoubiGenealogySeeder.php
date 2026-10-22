<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Services\Genealogy\LivingStatusClassifier;
use App\Services\Imports\AlZoubiDataset;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AlZoubiGenealogySeeder extends Seeder
{
    public function run(): void
    {
        $statistics = $this->import();

        $this->command?->info(sprintf(
            'Al-Zoubi import: created=%d updated=%d skipped=%d unresolved=%d conflicted=%d',
            $statistics['created'],
            $statistics['updated'],
            $statistics['skipped'],
            $statistics['unresolved'],
            $statistics['conflicted'],
        ));
    }

    /** @return array{created: int, updated: int, skipped: int, unresolved: int, conflicted: int} */
    public function import(): array
    {
        $dataset = app(AlZoubiDataset::class);
        $treeId = Family::query()->where('slug', FamilySeeder::SLUG)
            ->first()?->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->value('id');
        $peopleBefore = $treeId
            ? Person::query()->where('family_tree_id', $treeId)->count()
            : 0;
        $relationshipsBefore = $treeId
            ? PersonRelationship::query()->where('family_tree_id', $treeId)->count()
            : 0;

        DB::transaction(function (): void {
            $this->call([
                FamilySeeder::class,
                FamilyTreeSeeder::class,
                SourceSeeder::class,
                GenealogyPeopleSeeder::class,
                GenealogyRelationshipSeeder::class,
                BranchSeeder::class,
                BranchFounderSeeder::class,
            ]);
        });

        $tree = Family::query()->where('slug', FamilySeeder::SLUG)->sole()
            ->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->sole();
        // The curated dataset marks these records historical and sourced; classify them with that explicit basis.
        app(LivingStatusClassifier::class)->classifyUnknown($tree->id);
        $peopleCreated = $tree->people()->count() - $peopleBefore;
        $relationshipsCreated = $tree->personRelationships()->count() - $relationshipsBefore;
        $sourceStatistics = $dataset->statistics();
        $managedGraphRecords = $tree->people()->count() + $tree->personRelationships()->count();

        return [
            'created' => $peopleCreated + $relationshipsCreated,
            'updated' => $managedGraphRecords - $peopleCreated - $relationshipsCreated,
            'skipped' => $sourceStatistics['skipped'],
            'unresolved' => $sourceStatistics['unresolved'],
            'conflicted' => $sourceStatistics['conflicted'],
        ];
    }
}
