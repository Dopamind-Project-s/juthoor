<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->string('title')->nullable();
            $table->string('honorific')->nullable();
        });
        Schema::create('person_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('color', 7);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('person_attribute_assignments', function (Blueprint $table): void {
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_attribute_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['person_id', 'person_attribute_id']);
        });
        Schema::table('person_relationships', function (Blueprint $table): void {
            $table->string('canonical_key')->nullable();
            $table->unique(['family_tree_id', 'canonical_key'], 'person_rel_tree_key_uq');
        });
    }

    public function down(): void
    {
        Schema::table('person_relationships', function (Blueprint $table): void {
            $table->dropUnique('person_rel_tree_key_uq');
            $table->dropColumn('canonical_key');
        });
        Schema::dropIfExists('person_attribute_assignments');
        Schema::dropIfExists('person_attributes');
        Schema::table('people', fn (Blueprint $table) => $table->dropColumn(['title', 'honorific']));
    }
};
