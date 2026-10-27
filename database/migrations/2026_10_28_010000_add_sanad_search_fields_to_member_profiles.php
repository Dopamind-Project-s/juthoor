<?php

use App\Models\MemberProfile;
use App\Services\Community\MemberDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table): void {
            $table->json('search_fields_public')->nullable();
            $table->json('search_fields_members')->nullable();
        });
        MemberProfile::query()->with('entity')->chunkById(100, function ($profiles): void {
            foreach ($profiles as $profile) {
                app(MemberDirectory::class)->index($profile);
                $profile->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('member_profiles', fn (Blueprint $table) => $table->dropColumn(['search_fields_public', 'search_fields_members']));
    }
};
