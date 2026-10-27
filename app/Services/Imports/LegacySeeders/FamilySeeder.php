<?php

namespace App\Services\Imports\LegacySeeders;

use App\Models\Family;
use Illuminate\Database\Seeder;

/**
 * Bootstrap only: creates the family when it is missing and never rewrites an existing record, so administrator edits
 * (name, description, location, active state) survive repeated seeding (SD-01).
 */
class FamilySeeder extends Seeder
{
    public const SLUG = 'al-zoubi';

    /** @return array{name_ar: string, name_en: string, short_description: string, location: string, is_active: bool} */
    public static function attributes(): array
    {
        return [
            'name_ar' => 'عشيرة الزعبي',
            'name_en' => 'Al-Zoubi Family',
            'short_description' => 'عشيرة الزعبي في السلط، الأردن.',
            'location' => 'السلط — الأردن',
            'is_active' => true,
        ];
    }

    public function run(): void
    {
        Family::query()->firstOrCreate(['slug' => self::SLUG], self::attributes());
    }
}
