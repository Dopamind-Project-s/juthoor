<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2.2: genealogical assertions (claims about a person) kept beside the active genealogy. Claims never change
     * people or person_relationships by themselves; an accepted change request does. Evidence reuses citations and
     * review reuses change_requests.
     */
    public function up(): void
    {
        Schema::create('assertions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_tree_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('fact_type', 32);
            $table->string('value')->nullable();
            $table->foreignId('related_person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->string('relation', 24)->default('unclassified');
            $table->string('state', 16)->default('proposed');
            $table->string('verification_status', 32)->default('pending_review');
            $table->text('notes')->nullable();
            $table->string('import_key')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->index(['person_id', 'fact_type', 'state']);
            $table->unique(['family_tree_id', 'import_key']);
        });
        Schema::table('citations', function (Blueprint $table) {
            $table->foreignId('assertion_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('change_requests', function (Blueprint $table) {
            $table->foreignId('assertion_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assertion_id');
        });
        Schema::table('citations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assertion_id');
        });
        Schema::dropIfExists('assertions');
    }
};
