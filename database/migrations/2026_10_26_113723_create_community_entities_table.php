<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Community entities (tribe, clan, fakhd, sub-fakhd, branch/hamula) are content and administration units, separate from
 * genealogical branches: an entity may point at an existing branch or tree but never owns people. Managers hold an
 * entity-scoped role over an entity and its descendants. Content modules attach records to entities through one
 * polymorphic link table, as subject (what the record is about) or audience (whom it is explicitly addressed to).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('community_entities')->nullOnDelete();
            $table->string('entity_type', 32);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug');
            $table->json('alternate_names')->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('family_tree_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('publication_status', 16)->default('draft');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['family_id', 'slug'], 'community_entities_family_slug_uq');
            $table->index(['family_id', 'parent_id'], 'community_entities_family_parent_ix');
            $table->index(['family_id', 'publication_status'], 'community_entities_family_status_ix');
        });

        Schema::create('community_entity_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamps();

            $table->unique(['community_entity_id', 'user_id'], 'community_entity_managers_entity_user_uq');
            $table->index('user_id', 'community_entity_managers_user_ix');
        });

        Schema::create('entity_content_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_entity_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->string('relation', 16);
            $table->timestamps();

            $table->unique(['linkable_type', 'linkable_id', 'community_entity_id', 'relation'], 'entity_content_links_uq');
            $table->index(['community_entity_id', 'relation', 'linkable_type'], 'entity_content_links_entity_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_content_links');
        Schema::dropIfExists('community_entity_managers');
        Schema::dropIfExists('community_entities');
    }
};
