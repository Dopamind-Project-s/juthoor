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

        foreach ($rows as $row) {
            $person = Person::query()->updateOrCreate(
                ['family_tree_id' => $tree->id, 'canonical_key' => $row['person_key']],
                [
                    'first_name_ar' => $row['name_ar'],
                    'full_name_ar' => $row['name_ar'],
                    'notes' => 'جيل الجذر المشترك: '.($row['generation'] ?? 'غير محدد'),
                    'status' => Person::STATUS_HISTORICAL,
                    'verification_status' => Source::VERIFICATION_SOURCED,
                ],
            );
            $person->sources()->syncWithoutDetaching([
                $source->id => ['relation_type' => 'genealogy_record'],
            ]);
        }
    }
}
