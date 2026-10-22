<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Importer actions such as "qawasmeh_anchor_reconciled" exceed 24 characters;
     * SQLite ignores the length but PostgreSQL and MySQL reject the insert.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 24)->change();
        });
    }
};
