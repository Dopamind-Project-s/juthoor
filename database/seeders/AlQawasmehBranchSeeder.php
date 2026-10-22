<?php

namespace Database\Seeders;

use App\Services\Imports\AlQawasmehBranchImporter;
use Illuminate\Database\Seeder;

/**
 * Opt in only. Runs after the base Al-Zoubi seed or the shared ancestry. Its only change to existing records is the
 * audited ROOT-077 display-spelling reconciliation (the legacy spelling is kept as a variant); no person is duplicated.
 */
class AlQawasmehBranchSeeder extends Seeder
{
    public function run(): void
    {
        $counts = app(AlQawasmehBranchImporter::class)->import();
        $this->command?->info('Qawasmeh pending review: '.json_encode($counts, JSON_THROW_ON_ERROR));
    }
}
