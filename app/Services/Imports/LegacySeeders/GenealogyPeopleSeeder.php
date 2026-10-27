<?php

namespace App\Services\Imports\LegacySeeders;

use App\Models\Family;
use App\Models\Person;
use App\Models\Source;
use App\Services\Imports\AlZoubiDataset;
use Illuminate\Database\Seeder;

/**
 * Creates missing ready-root records only (SD-03). Existing records keep their name, status, verification, notes and
 * privacy; the dataset source is still attached as evidence. A name that differs from the dataset and is not a recorded
 * variant is refused before anything is written.
 */
class GenealogyPeopleSeeder extends Seeder
{
    /**
     * Values a newly created ready-root record receives.
     *
     * @param  array<string, mixed>  $row
     * @return array{first_name_ar: string, full_name_ar: string, notes: string, status: string, verification_status: string}
     */
    public static function attributesFor(array $row): array
    {
        return [
            'first_name_ar' => $row['name_ar'],
            'full_name_ar' => $row['name_ar'],
            'notes' => 'جيل الجذر المشترك: '.($row['generation'] ?? 'غير محدد'),
            'status' => Person::STATUS_HISTORICAL,
            'verification_status' => Source::VERIFICATION_SOURCED,
        ];
    }

    public static function matchesDataset(Person $person, string $name): bool
    {
        return $person->full_name_ar === $name || in_array($name, $person->alternate_names ?? [], true);
    }

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
        $conflicts = $existing->reject(fn (Person $person) => self::matchesDataset($person, $datasetNames[$person->canonical_key]))
            ->map(fn (Person $person) => "{$person->canonical_key}: existing [{$person->full_name_ar}]");
        if ($conflicts->isNotEmpty()) {
            throw new \RuntimeException("Existing root names differ from the curated dataset and are not recorded variants; reconcile them before seeding:\n".$conflicts->implode("\n"));
        }

        foreach ($rows as $row) {
            $person = $existing->get($row['person_key'])
                ?? Person::query()->create(['family_tree_id' => $tree->id, 'canonical_key' => $row['person_key']] + self::attributesFor($row));
            $person->sources()->syncWithoutDetaching([
                $source->id => ['relation_type' => 'genealogy_record'],
            ]);
        }
    }
}
