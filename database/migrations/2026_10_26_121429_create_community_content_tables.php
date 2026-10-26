<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.2–3.6 content modules on community entity pages: historical narratives, geographic locations, diwans/madafas
 * and notable people's positions and achievements. Every module shares the same governance columns (owner entity,
 * publication, verification, audience, review) and attaches to entities through `entity_content_links`; evidence comes
 * from the family source library through `content_citations`. Population estimates are entity facts with a required source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_entities', function (Blueprint $table) {
            $table->unsignedInteger('population_estimate')->nullable();
            $table->unsignedSmallInteger('population_estimate_year')->nullable();
            $table->foreignId('population_estimate_source_id')->nullable()->constrained('sources')->nullOnDelete();
        });

        Schema::create('content_citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->string('citable_type');
            $table->unsignedBigInteger('citable_id');
            $table->string('locator')->nullable();
            $table->text('url')->nullable();
            $table->text('notes')->nullable();
            $table->string('verification_status', 32)->default('pending_review');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['citable_type', 'citable_id'], 'content_citations_citable_ix');
            $table->index('source_id', 'content_citations_source_ix');
        });

        Schema::create('entity_narratives', function (Blueprint $table) {
            $this->governance($table);
            $table->string('section', 24);
            $table->string('account', 16)->default('primary');
            $table->string('title');
            $table->text('body');
            $table->string('period_label', 120)->nullable();
            $table->smallInteger('start_year')->nullable();
            $table->smallInteger('end_year')->nullable();
        });

        Schema::create('community_locations', function (Blueprint $table) {
            $this->governance($table);
            $table->string('name');
            $table->string('country', 120)->nullable();
            $table->string('governorate', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('era', 16);
            $table->string('kind', 16);
            $table->string('period_label', 120)->nullable();
            $table->text('description')->nullable();
        });

        Schema::create('diwans', function (Blueprint $table) {
            $this->governance($table);
            $table->string('name');
            $table->string('kind', 16);
            $table->text('description')->nullable();
            $table->string('governorate', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('public_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('google_place_id')->nullable();
            $table->string('maps_url', 2000)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->boolean('contact_consent')->default(false);
            $table->text('visit_instructions')->nullable();
            $table->boolean('is_private_residence')->default(false);
            $table->boolean('residence_consent')->default(false);
        });

        Schema::create('entity_achievements', function (Blueprint $table) {
            $this->governance($table);
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('category', 32);
            $table->string('title');
            $table->string('organization')->nullable();
            $table->text('description')->nullable();
            $table->string('period_label', 120)->nullable();
            $table->smallInteger('start_year')->nullable();
            $table->smallInteger('end_year')->nullable();

            $table->index('person_id', 'entity_achievements_person_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_achievements');
        Schema::dropIfExists('diwans');
        Schema::dropIfExists('community_locations');
        Schema::dropIfExists('entity_narratives');
        Schema::dropIfExists('content_citations');
        Schema::table('community_entities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('population_estimate_source_id');
            $table->dropColumn(['population_estimate', 'population_estimate_year']);
        });
    }

    /** Columns every community content module shares (HasCommunityEntities + CommunityContent). */
    private function governance(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('owner_entity_id')->constrained('community_entities')->cascadeOnDelete();
        $table->string('publication_status', 16)->default('draft');
        $table->string('verification_status', 32)->default('pending_review');
        $table->string('audience', 16)->default('public');
        $table->text('review_note')->nullable();
        $table->timestamp('submitted_at')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();

        $table->index(['owner_entity_id', 'publication_status']);
    }
};
