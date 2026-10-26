<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted validity of approved shared-ancestry links. A full dependency check records when it ran and a cheap
 * aggregate stamp of the data it covered; readers trust an approved link only while the stamp still matches.
 * Existing links start without a stamp, so they are revalidated before being shown again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cross_family_links', function (Blueprint $table) {
            $table->timestamp('validated_at')->nullable();
            $table->string('validation_stamp', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cross_family_links', function (Blueprint $table) {
            $table->dropColumn(['validated_at', 'validation_stamp']);
        });
    }
};
