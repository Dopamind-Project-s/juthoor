<?php

namespace Database\Seeders;

use App\Services\Imports\RootVariantImporter;
use Illuminate\Database\Seeder;

/** Opt in. Records ROOT name differences as reviewable claims; never changes a person. */
class AlZoubiRootVariantSeeder extends Seeder
{
    public function run(): void
    {
        $counts = app(RootVariantImporter::class)->import();
        $this->command?->info('Root name differences recorded for review: '.json_encode($counts, JSON_THROW_ON_ERROR));
    }
}
