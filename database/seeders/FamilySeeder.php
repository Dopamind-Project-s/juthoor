<?php

namespace Database\Seeders;

use App\Models\Family;
use Illuminate\Database\Seeder;

class FamilySeeder extends Seeder
{
    public const SLUG = 'al-zoubi';

    public function run(): void
    {
        Family::query()->updateOrCreate(['slug' => self::SLUG], [
            'name_ar' => 'عشيرة الزعبي',
            'name_en' => 'Al-Zoubi Family',
            'short_description' => 'عشيرة الزعبي في السلط، الأردن.',
            'location' => 'السلط — الأردن',
            'is_active' => true,
        ]);
    }
}
