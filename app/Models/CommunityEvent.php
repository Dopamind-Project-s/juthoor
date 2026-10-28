<?php

namespace App\Models;

use App\Models\Concerns\CommunityContent;
use App\Models\Concerns\HasLinkedPeople;
use App\Models\Concerns\HasMediaAttachments;
use App\Models\Concerns\HasNotices;
use App\Models\Concerns\HasPublicationSearch;
use App\Models\Concerns\HasPublicPlace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * A structured community event (v2.0 ch. 10), separate from news. Times are stored in UTC with the event's time zone;
 * schedule changes go through CommunityEventService, which keeps the replaced values as public notices.
 */
#[Fillable(['owner_entity_id', 'publication_status', 'verification_status', 'audience', 'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_by', 'sort_order', 'location_name', 'public_address', 'city', 'governorate', 'latitude', 'longitude', 'maps_url', 'contact_name', 'contact_phone', 'contact_consent', 'category', 'title', 'description', 'organizer', 'starts_at', 'ends_at', 'timezone', 'all_day'])]
class CommunityEvent extends Model
{
    use CommunityContent, HasLinkedPeople, HasMediaAttachments, HasNotices, HasPublicationSearch, HasPublicPlace;

    /** @var array<string, string> */
    public const CATEGORIES = [
        'wedding' => 'زفاف',
        'graduation' => 'تخرج',
        'achievement' => 'تكريم وإنجاز',
        'gathering' => 'لقاء عائلي',
        'meeting' => 'اجتماع عام',
        'initiative' => 'مبادرة',
        'other' => 'مناسبة أخرى',
    ];

    /** @var array<string, string> */
    public const STATUSES = ['scheduled' => 'قادمة', 'postponed' => 'مؤجلة', 'cancelled' => 'ملغاة', 'completed' => 'منتهية', 'ongoing' => 'جارية الآن'];

    /** Mirrors the column defaults so a just-saved event reads like a stored one. */
    protected $attributes = ['event_status' => 'scheduled', 'all_day' => false, 'contact_consent' => false];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'all_day' => 'boolean', 'latitude' => 'float', 'longitude' => 'float', 'contact_consent' => 'boolean'];
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(NewsPost::class, 'event_id');
    }

    /** @return array<string, array<int, mixed>> */
    public static function contentRules(Family $family): array
    {
        return [
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'organizer' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'timezone' => ['required', 'timezone:all'],
            'all_day' => ['nullable', 'boolean'],
            ...self::placeRules(),
        ];
    }

    /**
     * Form times are wall clock in the event's time zone; stored in UTC.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function prepareContentFields(array $fields): array
    {
        $zone = $fields['timezone'] ?? config('community.timezone');
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $fields)) {
                $fields[$field] = filled($fields[$field]) ? CarbonImmutable::parse($fields[$field], $zone)->utc() : null;
            }
        }

        return $fields;
    }

    public function getContentTitleAttribute(): string
    {
        return $this->title;
    }

    /** Scheduled events whose end (or start) has passed are shown as finished without changing the record. */
    public function getDisplayStatusAttribute(): string
    {
        if ($this->event_status === 'scheduled' && ($this->ends_at ?? $this->starts_at)->isPast()) {
            return 'completed';
        }

        if ($this->event_status === 'scheduled' && $this->ends_at && $this->starts_at->isPast() && $this->ends_at->isFuture()) {
            return 'ongoing';
        }

        return $this->event_status;
    }

    public function localStart(): CarbonImmutable
    {
        return $this->starts_at->setTimezone($this->timezone);
    }

    public function localEnd(): ?CarbonImmutable
    {
        return $this->ends_at?->setTimezone($this->timezone);
    }
}
