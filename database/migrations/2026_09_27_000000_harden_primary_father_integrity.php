<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_relationships', function (Blueprint $table) {
            // MySQL needs a person_id-leading index for the foreign key.
            $table->index('person_id', 'person_rel_person_ix');
            $table->dropUnique('person_rel_edge_uq');
            $table->unique(
                ['family_tree_id', 'person_id', 'related_person_id', 'relationship_type'],
                'person_rel_tree_edge_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::table('person_relationships', function (Blueprint $table) {
            $table->unique(
                ['person_id', 'related_person_id', 'relationship_type'],
                'person_rel_edge_uq',
            );
            $table->dropUnique('person_rel_tree_edge_uq');
            $table->dropIndex('person_rel_person_ix');
        });
    }
};
