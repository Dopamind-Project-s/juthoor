<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family websites on one platform: host mappings with an explicit lifecycle (no row = unconfigured) and the family's
 * portal branding as a reviewed draft and a published copy. No tenant database and no copied family data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('host', 253)->unique();
            $table->string('kind', 16);
            $table->string('status', 24)->index();
            $table->string('verification_token', 64);
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['family_id', 'status']);
        });

        Schema::create('family_portal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            $table->foreignId('draft_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('draft_updated_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_portal_settings');
        Schema::dropIfExists('family_domains');
    }
};
