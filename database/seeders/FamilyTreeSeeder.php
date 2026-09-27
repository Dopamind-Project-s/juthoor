<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\FamilyTree;
use Illuminate\Database\Seeder;

class FamilyTreeSeeder extends Seeder
{
    public const SLUG = 'main';

    public function run(): void
    {
        $family = Family::query()->where('slug', FamilySeeder::SLUG)->sole();

        FamilyTree::query()->updateOrCreate(
            ['family_id' => $family->id, 'slug' => self::SLUG],
            [
                'name_ar' => 'الشجرة الجامعة لعشيرة الزعبي',
                'name_en' => 'Al-Zoubi Main Family Tree',
                'description' => 'رسم نسب موحّد تشترك الفروع في جذره ولا يكرر الأسلاف.',
                'is_primary' => true,
                'is_active' => true,
            ],
        );
    }
}
