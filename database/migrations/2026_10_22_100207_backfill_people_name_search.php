<?php

use App\Services\ArabicNameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * People written while model events were muted (DatabaseSeeder used WithoutModelEvents) have no name_search, so
     * Arabic search and duplicate detection could not see them. Only the derived search column is filled.
     */
    public function up(): void
    {
        $normalizer = app(ArabicNameNormalizer::class);
        DB::table('people')->whereNull('name_search')->orderBy('id')->select(['id', 'full_name_ar'])
            ->chunkById(500, function ($people) use ($normalizer): void {
                foreach ($people as $person) {
                    DB::table('people')->where('id', $person->id)->update(['name_search' => $normalizer->normalize((string) $person->full_name_ar)]);
                }
            });
    }

    public function down(): void
    {
        // Derived data only; nothing to restore.
    }
};
