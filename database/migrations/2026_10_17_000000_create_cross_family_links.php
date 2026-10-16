<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->boolean('reviewers_only')->default(false));
        Schema::create('cross_family_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('left_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('right_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('left_family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('right_family_id')->constrained('families')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('status', 30)->default('pending_review')->index();
            $table->text('claim');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->string('dependency_hash', 64)->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->timestamps();
        });
        Schema::table('citations', function (Blueprint $table) {
            $table->foreignId('cross_family_link_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('citations', fn (Blueprint $table) => $table->dropConstrainedForeignId('cross_family_link_id'));
        Schema::dropIfExists('cross_family_links');
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropColumn('reviewers_only'));
    }
};
