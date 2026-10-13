<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_tree_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('checksum', 64);
            $table->string('file_path');
            $table->string('status', 16)->default('preview');
            $table->string('state_fingerprint', 64);
            $table->json('results');
            $table->json('created_ids')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->unique(['family_tree_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
