<?php

namespace Database\Seeders;

use App\Services\Imports\AlQawasmehBranchImporter;
use Illuminate\Database\Seeder;

/** Opt in only. Requires the existing shared ancestry; never seeds or modifies the legacy dataset. */
class AlQawasmehBranchSeeder extends Seeder
{
    public function run(): void
    {
        $counts = app(AlQawasmehBranchImporter::class)->import();
        $this->command?->info('Qawasmeh pending review: '.json_encode($counts, JSON_THROW_ON_ERROR));
    }
}
