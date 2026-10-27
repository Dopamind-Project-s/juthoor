<?php

namespace App\Services\Imports\LegacySeeders;

use App\Models\Family;
use App\Models\FamilyTree;
use Illuminate\Database\Seeder;

/**
 * Bootstrap only: creates the main tree when it is missing and never rewrites an existing tree's primary or active
 * state or descriptions (SD-02).
 */
class FamilyTreeSeeder extends Seeder
{
    public const SLUG = 'main';

    /** @return array{name_ar: string, name_en: string, description: string, is_primary: bool, is_active: bool} */
    public static function attributes(): array
    {
        return [
            'name_ar' => 'الشجرة الجامعة لعشيرة الزعبي',
            'name_en' => 'Al-Zoubi Main Family Tree',
            'description' => 'رسم نسب موحّد تشترك الفروع في جذره ولا يكرر الأسلاف.',
            'is_primary' => true,
            'is_active' => true,
        ];
    }

    public function run(): void
    {
        $family = Family::query()->where('slug', FamilySeeder::SLUG)->sole();

        FamilyTree::query()->firstOrCreate(
            ['family_id' => $family->id, 'slug' => self::SLUG],
            ['is_primary' => ! $family->familyTrees()->where('is_primary', true)->exists()] + self::attributes(),
        );
    }
}
