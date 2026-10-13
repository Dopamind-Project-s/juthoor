<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_tree_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('operation', 16);
            $table->json('payload');
            $table->json('base')->nullable();
            $table->string('base_fingerprint', 64)->nullable();
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('applied_audit_id')->nullable()->constrained('audit_logs')->restrictOnDelete();
            $table->timestamps();
            $table->index(['family_tree_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};
