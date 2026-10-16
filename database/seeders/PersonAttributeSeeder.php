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
            'rashidun-caliph' => ['أحد الخلفاء الراشدين', '#815B16'],
        ] as $key => [$label, $color]) {
            PersonAttribute::query()->firstOrCreate(['key' => $key], [
                'family_id' => null, 'label' => $label, 'color' => $color, 'is_active' => true,
            ]);
        }
    }
}
