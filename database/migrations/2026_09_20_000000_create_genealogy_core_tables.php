<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug')->unique();
            $table->text('short_description')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('family_trees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['family_id', 'slug'], 'trees_family_slug_uq');
            $table->index(['family_id', 'is_primary'], 'trees_family_primary_ix');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_tree_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable();
            $table->unsignedBigInteger('root_person_id')->nullable();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('level')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('parent_id', 'branches_parent_fk')->references('id')->on('branches')->nullOnDelete();
            $table->unique(['family_tree_id', 'slug'], 'branches_tree_slug_uq');
            $table->index(['family_tree_id', 'parent_id'], 'branches_tree_parent_ix');
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_tree_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('canonical_key')->nullable();
            $table->string('first_name_ar');
            $table->string('full_name_ar');
            $table->string('full_name_en')->nullable();
            $table->string('gender', 24)->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->date('death_date')->nullable();
            $table->unsignedSmallInteger('death_year')->nullable();
            $table->boolean('is_living')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['family_tree_id', 'canonical_key'], 'people_tree_canonical_uq');
            $table->index(['family_tree_id', 'branch_id'], 'people_tree_branch_ix');
            $table->index('full_name_ar', 'people_name_ar_ix');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreign('root_person_id', 'branches_root_person_fk')->references('id')->on('people')->nullOnDelete();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 48);
            $table->string('title');
            $table->string('reference')->nullable();
            $table->string('file_path')->nullable();
            $table->text('url')->nullable();
            $table->date('published_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('verification_status', 32)->default('pending_review');
            $table->timestamps();

            $table->unique(['family_id', 'reference'], 'sources_family_ref_uq');
            $table->index(['family_id', 'source_type', 'verification_status'], 'sources_lookup_ix');
        });

        Schema::create('person_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_tree_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_person_id')->constrained('people')->cascadeOnDelete();
            $table->string('relationship_type', 32);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('verification_status', 32)->default('pending_review');
            $table->timestamps();

            $table->unique(
                ['person_id', 'related_person_id', 'relationship_type'],
                'person_rel_edge_uq'
            );
            $table->index(
                ['family_tree_id', 'relationship_type', 'person_id'],
                'person_rel_lookup_ix'
            );
            $table->index('related_person_id', 'person_rel_related_ix');
        });

        Schema::create('person_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('relation_type', 48)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['person_id', 'source_id'], 'person_sources_person_source_uq');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('person_sources');
        Schema::dropIfExists('person_relationships');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('people');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('family_trees');
        Schema::dropIfExists('families');

        Schema::enableForeignKeyConstraints();
    }
};
