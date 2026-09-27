<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Services\Imports\AlZoubiDataset;
use Illuminate\Database\Seeder;

class GenealogyRelationshipSeeder extends Seeder
{
    public function run(): void
    {
        $family = Family::query()->where('slug', FamilySeeder::SLUG)->sole();
        $tree = $family->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->sole();
        $source = $family->sources()->where('reference', SourceSeeder::DATASET_REFERENCE)->sole();
        $rows = app(AlZoubiDataset::class)->readyRootRows();
        $people = Person::query()->where('family_tree_id', $tree->id)
            ->whereNotNull('canonical_key')->get()->keyBy('canonical_key');

        foreach ($rows as $row) {
            $fatherKey = $row['father_key'] ?? null;

            if ($fatherKey === null) {
                continue;
            }

            if (! $people->has($row['person_key']) || ! $people->has($fatherKey)) {
                throw new \RuntimeException("Missing confirmed genealogy record for [{$row['person_key']}].");
            }

            PersonRelationship::query()->updateOrCreate(
                [
                    'person_id' => $people[$row['person_key']]->id,
                    'related_person_id' => $people[$fatherKey]->id,
                    'relationship_type' => PersonRelationship::TYPE_FATHER,
                ],
                [
                    'family_tree_id' => $tree->id,
                    'is_primary' => true,
                    'source_id' => $source->id,
                    'verification_status' => Source::VERIFICATION_SOURCED,
                ],
            );
        }

        $tree->update(['root_person_id' => $people['ROOT-001']->id]);
    }
}
