<?php

namespace Database\Seeders;

use App\Models\PersonAttribute;
use Illuminate\Database\Seeder;

class PersonAttributeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'prophet' => ['نبي', '#20604F'],
            'companion' => ['صحابي', '#275D8C'],
            'prophet-grandson' => ['حفيد الرسول', '#704375'],
            'tribe-ancestor' => ['جد العشيرة', '#524B24'],
            'branch-founder' => ['مؤسس فرع / فخذ', '#36576B'],
            'rashidun-caliph' => ['أحد الخلفاء الراشدين', '#815B16'],
            // v2.0 founder levels; existing keys above keep their meaning and assignments.
            'tribe-founder' => ['مؤسس القبيلة', '#6B3A2A'],
            'clan-founder' => ['مؤسس العشيرة', '#3F5A2B'],
            'fakhd-founder' => ['مؤسس الفخذ', '#2D4F73'],
            'sub-branch-founder' => ['الجد المؤسس لفرع تابع', '#5B4A7A'],
        ] as $key => [$label, $color]) {
            PersonAttribute::query()->firstOrCreate(['key' => $key, 'family_id' => null], [
                'family_id' => null, 'label' => $label, 'color' => $color, 'is_active' => true,
            ]);
        }
    }
}
