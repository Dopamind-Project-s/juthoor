<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Functional family roles on top of the existing membership roles (reader / editor / admin). Membership roles keep their
 * behaviour in code; these tables hold the versioned permission catalog, role templates, administrator customizations and
 * per-family assignments. An assignment belongs to a membership, so leaving the family removes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('scope', 16);
            $table->string('label_ar');
            $table->string('catalog_version', 32);
            $table->timestamp('deprecated_at')->nullable();
            $table->string('replaced_by', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('scope', 16);
            $table->string('label_ar');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('catalog_version', 32)->nullable();
            $table->timestamp('customized_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 16);
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('family_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['family_membership_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_role_assignments');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
