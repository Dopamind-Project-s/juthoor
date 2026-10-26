<?php

namespace App\Models;

use App\Models\Concerns\CommunityContent;
use App\Models\Concerns\HasUsageRights;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A file in the family media library: image, document or audio. Stored once per family (checksum) on a private disk
 * under a generated name and served only by MediaController. Viewers get a metadata-free display copy of images;
 * the original stays available to the owner entity's managers.
 */
#[Fillable(['owner_entity_id', 'publication_status', 'verification_status', 'audience', 'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_by', 'sort_order', 'rights_status', 'rights_holder', 'rights_note', 'title', 'description', 'alt_text', 'provenance', 'historical_period', 'historical_year'])]
class MediaItem extends Model
{
    use CommunityContent, HasUsageRights;

    /** @var array<string, string> */
    public const CATEGORIES = ['image' => 'صورة', 'document' => 'وثيقة', 'audio' => 'تسجيل صوتي'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }

    /** @return array<string, array<int, mixed>> */
    public static function contentRules(Family $family): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'alt_text' => ['nullable', 'string', 'max:500'],
            'provenance' => ['nullable', 'string', 'max:2000'],
            'historical_period' => ['nullable', 'string', 'max:120'],
            'historical_year' => ['nullable', 'integer', 'between:-3000,2100'],
            ...self::rightsRules(),
        ];
    }

    public function getContentTitleAttribute(): string
    {
        return $this->title;
    }

    public function isImage(): bool
    {
        return $this->category === 'image';
    }

    /** URL of the viewer copy (images) or the file itself; the route decides who may read it. */
    public function url(string $variant = 'display'): string
    {
        return route('media.show', [$this->uuid, $variant]);
    }
}
