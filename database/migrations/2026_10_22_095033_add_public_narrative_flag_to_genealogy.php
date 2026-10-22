<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Source that published the user-supplied shared ancestry as a submitted narrative (2026-10-16). */
    private const NARRATIVE_SOURCE = 'al-zoubi-shared-ancestry-user-2026-10-16';

    /**
     * Replaces the hard-coded ROOT-001..079 visibility exception with an explicit flag. The backfill marks exactly
     * the rows the old scopes exposed while pending review; nothing else becomes public.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->boolean('public_narrative')->default(false);
        });
        Schema::table('person_relationships', function (Blueprint $table) {
            $table->boolean('public_narrative')->default(false);
        });

        $rootKeys = array_map(fn (int $position) => sprintf('ROOT-%03d', $position), range(1, 79));
        $edgeKeys = array_map(fn (int $position) => sprintf('ROOT-%03d-father-ROOT-%03d', $position, $position - 1), range(2, 79));
        $sourceIds = DB::table('sources')->where('reference', self::NARRATIVE_SOURCE)->pluck('id');
        $treeIds = DB::table('family_trees')->join('families', 'families.id', '=', 'family_trees.family_id')
            ->where('families.slug', 'al-zoubi')->pluck('family_trees.id');

        DB::table('people')
            ->where('verification_status', 'pending_review')
            ->whereIn('canonical_key', $rootKeys)
            ->whereIn('family_tree_id', $treeIds)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('person_sources')
                ->whereColumn('person_sources.person_id', 'people.id')->whereIn('person_sources.source_id', $sourceIds))
            ->update(['public_narrative' => true]);

        DB::table('person_relationships')
            ->where('verification_status', 'pending_review')
            ->whereIn('canonical_key', $edgeKeys)
            ->whereIn('source_id', $sourceIds)
            ->update(['public_narrative' => true]);
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('public_narrative');
        });
        Schema::table('person_relationships', function (Blueprint $table) {
            $table->dropColumn('public_narrative');
        });
    }
};
