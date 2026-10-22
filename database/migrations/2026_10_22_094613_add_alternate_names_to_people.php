<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recorded spelling variants (e.g. ذبيان for ذيبان) stay searchable without becoming the display name.
     * alternate_name_search holds normalized variants delimited by "|" for exact and partial matching.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->json('alternate_names')->nullable();
            $table->text('alternate_name_search')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['alternate_names', 'alternate_name_search']);
        });
    }
};
