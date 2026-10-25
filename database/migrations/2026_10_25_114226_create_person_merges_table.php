<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2.3 (JTH-10, UC-06): a merged duplicate is kept and pointed at its survivor; person_merges is the
     * immutable operation record with the row-level change journal needed to undo it exactly.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->constrained('people')->restrictOnDelete();
        });
        Schema::create('person_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_tree_id')->constrained()->restrictOnDelete();
            $table->foreignId('survivor_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('merged_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('fingerprint', 64);
            $table->json('decisions');
            $table->json('journal');
            $table->string('status', 16)->default('applied');
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->text('undo_reason')->nullable();
            $table->timestamps();
            $table->index(['family_tree_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_merges');
        Schema::table('people', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }
};
