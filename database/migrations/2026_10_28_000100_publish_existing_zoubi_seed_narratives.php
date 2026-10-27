<?php

use App\Models\Family;
use App\Services\AuditLogger;
use App\Services\Imports\UnifiedGenealogyDataset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $family = Family::query()->where('slug', 'al-zoubi')->first();
            $tree = $family?->familyTrees()->where('slug', 'main')->first();
            if (! $tree) {
                return;
            }
            $changed = 0;
            foreach (array_chunk(array_column(app(UnifiedGenealogyDataset::class)->data()['people'], 'key'), 300) as $keys) {
                $changed += $tree->people()->whereIn('canonical_key', $keys)
                    ->where('public_narrative', false)->update(['public_narrative' => true]);
            }
            app(AuditLogger::class)->record(null, $family, $tree, 'seed_public_approved', null, [
                'people_changed' => $changed,
                'approval' => 'Administrator requested public seed names and graph, including unknown living status.',
                'sensitive_fields' => 'Existing field privacy remains enforced.',
            ]);
        });
    }

    public function down(): void
    {
        // Public approvals cannot be safely reverted without restoring each record's prior approval state.
    }
};
