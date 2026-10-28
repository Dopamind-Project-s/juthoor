<?php

use App\Models\CommunityEvent;
use App\Models\NewsPost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_posts', function (Blueprint $table): void {
            $table->text('searchable_text')->nullable();
            $table->boolean('is_featured')->default(false);
        });
        Schema::table('community_events', fn (Blueprint $table) => $table->text('searchable_text')->nullable());
        foreach ([NewsPost::class, CommunityEvent::class] as $model) {
            $model::query()->chunkById(100, function ($records): void {
                foreach ($records as $record) {
                    $record->newQuery()->whereKey($record->id)->toBase()->update(['searchable_text' => $record->publicationSearchText()]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('news_posts', fn (Blueprint $table) => $table->dropColumn(['searchable_text', 'is_featured']));
        Schema::table('community_events', fn (Blueprint $table) => $table->dropColumn('searchable_text'));
    }
};
