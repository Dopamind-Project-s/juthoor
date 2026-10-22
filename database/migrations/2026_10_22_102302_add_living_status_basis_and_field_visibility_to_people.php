<?php

use App\Services\Genealogy\LivingStatusClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2.1: unknown living status stops meaning "deceased". Existing explicit values are kept and marked as
     * legacy; unknown records are classified only with explicit evidence (see LivingStatusClassifier). Everything
     * else stays unknown and therefore private.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('living_status_basis', 48)->nullable();
            $table->json('field_visibility')->nullable();
        });

        DB::table('people')->whereNotNull('is_living')->update(['living_status_basis' => LivingStatusClassifier::BASIS_LEGACY]);
        app(LivingStatusClassifier::class)->classifyUnknown();
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['living_status_basis', 'field_visibility']);
        });
    }
};
