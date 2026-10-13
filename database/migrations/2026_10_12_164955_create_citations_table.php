<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->foreignId('family_tree_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('person_relationship_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('field_key', 64);
            $table->string('locator')->nullable();
            $table->text('url')->nullable();
            $table->text('notes')->nullable();
            $table->string('verification_status', 32)->default('pending_review');
            $table->timestamps();
            $table->index(['family_tree_id', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citations');
    }
};
