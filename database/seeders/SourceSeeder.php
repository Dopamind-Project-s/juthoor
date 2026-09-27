<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\Source;
use App\Services\Imports\AlZoubiDataset;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public const DATASET_REFERENCE = 'juthoor-alzoubi-genealogy-v4';

    public function run(): void
    {
        $family = Family::query()->where('slug', FamilySeeder::SLUG)->sole();

        Source::query()->updateOrCreate(
            ['family_id' => $family->id, 'reference' => self::DATASET_REFERENCE],
            [
                'source_type' => 'genealogy_dataset',
                'title' => 'مجموعة بيانات نسب عشيرة الزعبي — الإصدار الرابع',
                'file_path' => AlZoubiDataset::PATH,
                'notes' => 'المصدر المنسق للجذر المشترك؛ تحفظ مفاتيح السجلات للتتبع.',
                'verification_status' => Source::VERIFICATION_SOURCED,
            ],
        );
    }
}
