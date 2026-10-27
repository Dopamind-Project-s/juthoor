<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Services\Imports\AlZoubiDataset;
use Illuminate\Database\Seeder;

/**
 * Creates missing ready-root father edges only (SD-04). An existing edge keeps its source and verification; a record
 * that already has another father is refused before anything is written, never given a second father.
 */
class GenealogyRelationshipSeeder extends Seeder
{
    public function run(): void
    {
        $family = Family::query()->where('slug', FamilySeeder::SLUG)->sole();
        $tree = $family->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->sole();
        $source = $family->sources()->where('reference', SourceSeeder::DATASET_REFERENCE)->sole();
        $rows = array_filter(app(AlZoubiDataset::class)->readyRootRows(), fn (array $row): bool => ($row['father_key'] ?? null) !== null);
        $people = Person::query()->where('family_tree_id', $tree->id)
            ->whereNotNull('canonical_key')->get()->keyBy('canonical_key');

        $conflicts = [];
        foreach ($rows as $row) {
            if (! $people->has($row['person_key']) || ! $people->has($row['father_key'])) {
                throw new \RuntimeException("Missing confirmed genealogy record for [{$row['person_key']}].");
            }
            $otherFathers = PersonRelationship::query()->where('person_id', $people[$row['person_key']]->id)
                ->where('relationship_type', PersonRelationship::TYPE_FATHER)
                ->where('related_person_id', '!=', $people[$row['father_key']]->id)->with('relatedPerson')->get();
            foreach ($otherFathers as $edge) {
                $conflicts[] = "{$row['person_key']}: existing father [".($edge->relatedPerson?->canonical_key ?? $edge->related_person_id)."], dataset [{$row['father_key']}]";
            }
        }
        if ($conflicts !== []) {
            throw new \RuntimeException("Existing fathers differ from the curated dataset; resolve them through review before seeding:\n".implode("\n", $conflicts));
        }

        foreach ($rows as $row) {
            PersonRelationship::query()->firstOrCreate(
                [
                    'person_id' => $people[$row['person_key']]->id,
                    'related_person_id' => $people[$row['father_key']]->id,
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

        if ($tree->root_person_id === null) {
            $tree->update(['root_person_id' => $people['ROOT-001']->id]);
        } elseif ($tree->root_person_id !== $people['ROOT-001']->id) {
            $this->command?->warn('Tree root differs from ROOT-001; the existing root was preserved.');
        }
    }
}
