<?php

namespace App\Services\Imports;

/** Read-only defaults used by the authoritative development bootstrap. */
final class AlZoubiSeedDefaults
{
    public static function familyAttributes(): array
    {
        return [
            'name_ar' => 'عشيرة الزعبي',
            'name_en' => 'Al-Zoubi Family',
            'short_description' => 'عشيرة الزعبي في السلط، الأردن.',
            'location' => 'السلط — الأردن',
            'is_active' => true,
        ];
    }

    public static function treeAttributes(): array
    {
        return [
            'name_ar' => 'الشجرة الجامعة لعشيرة الزعبي',
            'name_en' => 'Al-Zoubi Main Family Tree',
            'description' => 'رسم نسب موحّد تشترك الفروع في جذره ولا يكرر الأسلاف.',
            'is_primary' => true,
            'is_active' => true,
        ];
    }
}
