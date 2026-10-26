<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.7 media and heritage archive. `media_items` are community content records (same governance as the Phase 3.2–3.6
 * modules) whose files live on a private disk under generated names; one file is stored once per family (checksum)
 * and attached to any number of records through `media_attachments`, which blocks deleting an attached file.
 * `archive_records` are heritage archive entries (JTH-15) with rights and an optional genealogical person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table) {
            $this->governance($table);
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('display_path')->nullable();
            $table->string('original_name');
            $table->string('extension', 10);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('category', 16);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('alt_text', 500)->nullable();
            $this->rights($table);
            $table->text('provenance')->nullable();
            $table->string('historical_period', 120)->nullable();
            $table->smallInteger('historical_year')->nullable();

            $table->unique(['family_id', 'checksum'], 'media_items_family_checksum_uq');
        });

        Schema::create('media_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->restrictOnDelete();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('caption', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['media_item_id', 'attachable_type', 'attachable_id'], 'media_attachments_uq');
            $table->index(['attachable_type', 'attachable_id'], 'media_attachments_attachable_ix');
        });

        Schema::create('archive_records', function (Blueprint $table) {
            $this->governance($table);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 24);
            $table->text('original_source')->nullable();
            $table->string('period_label', 120)->nullable();
            $table->smallInteger('start_year')->nullable();
            $table->smallInteger('end_year')->nullable();
            $table->string('contributor')->nullable();
            $table->foreignId('person_id')->nullable()->constrained('people')->restrictOnDelete();
            $this->rights($table);
        });

        Schema::table('community_entities', function (Blueprint $table) {
            $table->foreignId('cover_media_id')->nullable()->constrained('media_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('community_entities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_media_id');
        });
        Schema::dropIfExists('archive_records');
        Schema::dropIfExists('media_attachments');
        Schema::dropIfExists('media_items');
    }

    /** Same governance columns as the Phase 3.2–3.6 content modules. */
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

    /** Copyright and usage permission, checked before public publication. */
    private function rights(Blueprint $table): void
    {
        $table->string('rights_status', 24)->default('unknown');
        $table->string('rights_holder')->nullable();
        $table->text('rights_note')->nullable();
    }
};
