<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_trees', function (Blueprint $table) {
            $table->unsignedBigInteger('root_person_id')->nullable()->after('family_id');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->string('verification_status', 32)->default('pending_review')->after('status');
            $table->index(['family_tree_id', 'full_name_ar'], 'people_tree_name_ix');
        });

        Schema::table('family_trees', function (Blueprint $table) {
            $table->foreign('root_person_id', 'trees_root_person_fk')
                ->references('id')->on('people')->nullOnDelete();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('parent_branch_id')->nullable()->after('family_tree_id');
            $table->string('branch_type', 32)->default('family_group')->after('root_person_id');
            $table->string('membership_mode', 32)->default('descendants')->after('description');
            $table->string('verification_status', 32)->default('pending_review')->after('is_active');
        });

        // Preserve previously seeded hierarchy while moving to an unambiguous column name.
        DB::table('branches')->whereNotNull('parent_id')->update([
            'parent_branch_id' => DB::raw('parent_id'),
        ]);

        Schema::table('branches', function (Blueprint $table) {
            $table->foreign('parent_branch_id', 'branches_parent_branch_fk')
                ->references('id')->on('branches')->nullOnDelete();
            $table->index(['family_tree_id', 'parent_branch_id'], 'branches_tree_parent_branch_ix');
            $table->index(['family_tree_id', 'root_person_id'], 'branches_tree_root_ix');
            $table->index(['family_tree_id', 'branch_type'], 'branches_tree_type_ix');
        });

        if (DB::table('people')->whereNotNull('branch_id')->exists()) {
            throw new RuntimeException('Cannot remove legacy people.branch_id while populated records exist.');
        }

        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex('people_tree_branch_ix');
            $table->dropColumn('branch_id');
        });

        Schema::table('branches', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['parent_id']);
            } else {
                $table->dropForeign('branches_parent_fk');
            }
            $table->dropIndex('branches_tree_parent_ix');
            $table->dropColumn(['parent_id', 'level']);
        });

        Schema::table('person_relationships', function (Blueprint $table) {
            $table->index(
                ['family_tree_id', 'related_person_id', 'relationship_type'],
                'person_rel_reverse_ix',
            );
            $table->index(
                ['family_tree_id', 'verification_status', 'is_primary'],
                'person_rel_public_ix',
            );
        });
    }

    public function down(): void
    {
        Schema::table('person_relationships', function (Blueprint $table) {
            $table->dropIndex('person_rel_reverse_ix');
            $table->dropIndex('person_rel_public_ix');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('family_tree_id');
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->index(['family_tree_id', 'branch_id'], 'people_tree_branch_ix');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('family_tree_id');
            $table->unsignedSmallInteger('level')->nullable()->after('description');
        });

        DB::table('branches')->whereNotNull('parent_branch_id')->update([
            'parent_id' => DB::raw('parent_branch_id'),
        ]);

        Schema::table('branches', function (Blueprint $table) {
            $table->foreign('parent_id', 'branches_parent_fk')
                ->references('id')->on('branches')->nullOnDelete();
            $table->index(['family_tree_id', 'parent_id'], 'branches_tree_parent_ix');
        });

        Schema::table('branches', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['parent_branch_id']);
            } else {
                $table->dropForeign('branches_parent_branch_fk');
            }
            $table->dropIndex('branches_tree_parent_branch_ix');
            $table->dropIndex('branches_tree_root_ix');
            $table->dropIndex('branches_tree_type_ix');
            $table->dropColumn(['parent_branch_id', 'branch_type', 'membership_mode', 'verification_status']);
        });

        Schema::table('family_trees', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['root_person_id']);
            } else {
                $table->dropForeign('trees_root_person_fk');
            }
            $table->dropColumn('root_person_id');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex('people_tree_name_ix');
            $table->dropColumn('verification_status');
        });
    }
};
