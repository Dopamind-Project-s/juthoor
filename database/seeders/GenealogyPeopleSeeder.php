<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\Person;
use App\Models\Source;
use App\Services\Imports\AlZoubiDataset;
use Illuminate\Database\Seeder;

class GenealogyPeopleSeeder extends Seeder
{
    public function run(): void
    {
        $family = Family::query()->where('slug', FamilySeeder::SLUG)->sole();
        $tree = $family->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->sole();
        $source = $family->sources()->where('reference', SourceSeeder::DATASET_REFERENCE)->sole();
        $rows = app(AlZoubiDataset::class)->readyRootRows();

        if (count($rows) !== 77) {
            throw new \RuntimeException('The curated dataset must contain exactly 77 ready root records.');
        }

        $datasetNames = array_column($rows, 'name_ar', 'person_key');
        $existing = $tree->people()->whereIn('canonical_key', array_keys($datasetNames))->get()->keyBy('canonical_key');
        $conflicts = $existing->reject(fn (Person $person) => $this->matchesDataset($person, $datasetNames[$person->canonical_key]))
            ->map(fn (Person $person) => "{$person->canonical_key}: existing [{$person->full_name_ar}]");
        if ($conflicts->isNotEmpty()) {
            throw new \RuntimeException("Existing root names differ from the curated dataset and are not recorded variants; reconcile them before seeding:\n".$conflicts->implode("\n"));
        }

        foreach ($rows as $row) {
            $person = $existing->get($row['person_key']);
            $attributes = [
                'notes' => 'جيل الجذر المشترك: '.($row['generation'] ?? 'غير محدد'),
                'status' => Person::STATUS_HISTORICAL,
                'verification_status' => Source::VERIFICATION_SOURCED,
            ];
            // A reconciled display spelling is kept; the dataset spelling is already a recorded variant.
            if (! $person || $person->full_name_ar === $row['name_ar']) {
                $attributes += ['first_name_ar' => $row['name_ar'], 'full_name_ar' => $row['name_ar']];
            }
            $person = Person::query()->updateOrCreate(
                ['family_tree_id' => $tree->id, 'canonical_key' => $row['person_key']],
                $attributes,
            );
            $person->sources()->syncWithoutDetaching([
                $source->id => ['relation_type' => 'genealogy_record'],
            ]);
        }
    }

    private function matchesDataset(Person $person, string $name): bool
    {
        return $person->full_name_ar === $name || in_array($name, $person->alternate_names ?? [], true);
    }
}
