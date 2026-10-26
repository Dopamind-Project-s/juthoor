<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4. News posts (incl. death notices and condolences) and community events are content modules with the shared
 * governance columns; news adds a publication window and sensitive-approval fields. Public updates, corrections,
 * cancellations and schedule changes are kept in `content_notices`; existing people are referenced through
 * `content_person_links`. The voluntary member directory is separate from genealogy: one opt-in profile per user and
 * family, with per-field visibility and precomputed searchable text per audience.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_events', function (Blueprint $table) {
            $this->governance($table);
            $table->string('category', 24);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('organizer')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 64);
            $table->boolean('all_day')->default(false);
            $table->string('event_status', 16)->default('scheduled');
            $this->place($table);

            $table->index('starts_at');
        });

        Schema::create('news_posts', function (Blueprint $table) {
            $this->governance($table);
            $table->string('category', 24);
            $table->string('title');
            $table->string('summary', 500)->nullable();
            $table->text('body');
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('community_events')->nullOnDelete();
            $table->string('deceased_name')->nullable();
            $table->foreignId('deceased_person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->date('death_date')->nullable();
            $table->text('funeral_prayer')->nullable();
            $table->text('burial_place')->nullable();
            $table->text('condolence_place')->nullable();
            $table->text('condolence_times')->nullable();
            $this->place($table);
            $table->boolean('family_consent')->default(false);
            $table->foreignId('sensitive_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sensitive_confirmed_at')->nullable();
            $table->text('sensitive_note')->nullable();

            $table->index('publish_at');
        });

        Schema::create('content_notices', function (Blueprint $table) {
            $table->id();
            $table->string('notable_type');
            $table->unsignedBigInteger('notable_id');
            $table->string('kind', 24);
            $table->text('message');
            $table->json('previous')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['notable_type', 'notable_id'], 'content_notices_notable_ix');
        });

        Schema::create('content_person_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->timestamps();

            $table->unique(['person_id', 'linkable_type', 'linkable_id'], 'content_person_links_uq');
            $table->index(['linkable_type', 'linkable_id'], 'content_person_links_linkable_ix');
        });

        Schema::create('member_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->foreignId('entity_id')->nullable()->constrained('community_entities')->nullOnDelete();
            $table->string('affiliation_status', 16)->default('unverified');
            $table->foreignId('affiliation_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('affiliation_reviewed_at')->nullable();
            $table->text('affiliation_note')->nullable();
            $table->string('profession')->nullable();
            $table->string('specialization')->nullable();
            $table->string('sector', 32)->nullable();
            $table->json('skills')->nullable();
            $table->text('experience')->nullable();
            $table->string('organization')->nullable();
            $table->string('business_name')->nullable();
            $table->string('business_category', 32)->nullable();
            $table->text('services')->nullable();
            $table->string('governorate', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->json('links')->nullable();
            $table->text('collaboration')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('photo_disk', 64)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('audience', 16)->default('members');
            $table->json('field_visibility')->nullable();
            $table->string('member_fields', 500)->default('|');
            $table->string('public_fields', 500)->default('|');
            $table->text('search_members')->nullable();
            $table->text('search_public')->nullable();
            $table->boolean('is_listed')->default(false);
            $table->timestamp('listed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('suspension_note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'family_id'], 'member_profiles_user_family_uq');
            $table->index(['family_id', 'is_listed'], 'member_profiles_family_listed_ix');
        });

        Schema::create('directory_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 32);
            $table->text('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_reports');
        Schema::dropIfExists('member_profiles');
        Schema::dropIfExists('content_person_links');
        Schema::dropIfExists('content_notices');
        Schema::dropIfExists('news_posts');
        Schema::dropIfExists('community_events');
    }

    /** Same governance columns as the Phase 3 content modules. */
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

    /** A public place (never a private address without consent) with optional coordinates and Maps link. */
    private function place(Blueprint $table): void
    {
        $table->string('location_name')->nullable();
        $table->string('public_address')->nullable();
        $table->string('city', 120)->nullable();
        $table->string('governorate', 120)->nullable();
        $table->decimal('latitude', 10, 7)->nullable();
        $table->decimal('longitude', 10, 7)->nullable();
        $table->string('maps_url', 2000)->nullable();
        $table->string('contact_name')->nullable();
        $table->string('contact_phone', 40)->nullable();
        $table->boolean('contact_consent')->default(false);
    }
};
