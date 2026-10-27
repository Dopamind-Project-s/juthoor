<?php

namespace App\Services\Imports\LegacySeeders;

use App\Models\Family;
use App\Models\Source;
use App\Services\Imports\AlZoubiDataset;
use Illuminate\Database\Seeder;

/** Bootstrap only: the dataset source is created when missing; a reviewed title, note or status is never reset. */
class SourceSeeder extends Seeder
{
    public const DATASET_REFERENCE = 'juthoor-alzoubi-genealogy-v4';

    /** @return array{source_type: string, title: string, file_path: string, notes: string, verification_status: string} */
    public static function attributes(): array
    {
        return [
            'source_type' => 'genealogy_dataset',
            'title' => 'مجموعة بيانات نسب عشيرة الزعبي — الإصدار الرابع',
            'file_path' => AlZoubiDataset::PATH,
            'notes' => 'المصدر المنسق للجذر المشترك؛ تحفظ مفاتيح السجلات للتتبع.',
            'verification_status' => Source::VERIFICATION_SOURCED,
        ];
    }

    public function run(): void
    {
        $family = Family::query()->where('slug', FamilySeeder::SLUG)->sole();

        Source::query()->firstOrCreate(
            ['family_id' => $family->id, 'reference' => self::DATASET_REFERENCE],
            self::attributes(),
        );
    }
}
