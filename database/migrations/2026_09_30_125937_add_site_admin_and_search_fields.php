<?php

use App\Services\ArabicNameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_super_admin');
        });
        Schema::table('people', function (Blueprint $table) {
            $table->string('name_search')->nullable()->after('full_name_ar');
            $table->index(['family_tree_id', 'name_search'], 'people_tree_name_search_ix');
        });

        $normalizer = new ArabicNameNormalizer;
        DB::table('people')->orderBy('id')->eachById(function ($person) use ($normalizer): void {
            DB::table('people')->where('id', $person->id)->update([
                'name_search' => $normalizer->normalize($person->full_name_ar),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex('people_tree_name_search_ix');
            $table->dropColumn('name_search');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin', 'is_active']);
        });
    }
};
