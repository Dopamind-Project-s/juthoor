<?php

namespace App\Models;

use App\Models\Concerns\CommunityContent;
use App\Models\Concerns\HasLinkedPeople;
use App\Models\Concerns\HasMediaAttachments;
use App\Models\Concerns\HasNotices;
use App\Models\Concerns\HasPublicPlace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * News, official announcements, death notices, condolences, initiatives, meetings and public notices (v2.0 ch. 10).
 * Visible only inside its publication window. Death notices and condolences are sensitive: published only by a
 * family administrator who confirms the family's consent; they never change the linked genealogy record.
 */
#[Fillable(['owner_entity_id', 'publication_status', 'verification_status', 'audience', 'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_by', 'sort_order', 'location_name', 'public_address', 'city', 'governorate', 'latitude', 'longitude', 'maps_url', 'contact_name', 'contact_phone', 'contact_consent', 'category', 'title', 'summary', 'body', 'publish_at', 'expires_at', 'event_id', 'deceased_name', 'deceased_person_id', 'death_date', 'funeral_prayer', 'burial_place', 'condolence_place', 'condolence_times', 'family_consent', 'sensitive_note'])]
class NewsPost extends Model
{
    use CommunityContent, HasLinkedPeople, HasMediaAttachments, HasNotices, HasPublicPlace;

    /** @var array<string, string> */
    public const CATEGORIES = [
        'news' => 'خبر',
        'announcement' => 'إعلان رسمي',
        'death' => 'نعي ووفاة',
        'condolence' => 'عزاء',
        'initiative' => 'مبادرة مجتمعية',
        'meeting' => 'لقاء أو اجتماع',
        'notice' => 'تنويه عام',
    ];

    /** Categories that need the family administrator's confirmation of consent before publication. */
    public const SENSITIVE = ['death', 'condolence'];

    /** Mirrors the column defaults so a just-saved post reads like a stored one. */
    protected $attributes = ['family_consent' => false, 'contact_consent' => false];

    protected function casts(): array
    {
        return ['publish_at' => 'datetime', 'expires_at' => 'datetime', 'cancelled_at' => 'datetime', 'death_date' => 'date', 'sensitive_confirmed_at' => 'datetime',
            'latitude' => 'float', 'longitude' => 'float', 'contact_consent' => 'boolean', 'family_consent' => 'boolean'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CommunityEvent::class, 'event_id');
    }

    public function deceasedPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'deceased_person_id');
    }

    public function sensitiveConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sensitive_confirmed_by');
    }

    public function isSensitive(): bool
    {
        return in_array($this->category, self::SENSITIVE, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @return array<string, array<int, mixed>> */
    public static function contentRules(Family $family): array
    {
        $people = Rule::exists('people', 'id')->whereNull('merged_into_id')->whereIn('family_tree_id', $family->familyTrees()->pluck('id')->all());

        return [
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:20000'],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:publish_at'],
            'deceased_name' => ['nullable', 'string', 'max:255'],
            'deceased_person_id' => ['nullable', 'integer', $people],
            'death_date' => ['nullable', 'date', 'before_or_equal:today'],
            'funeral_prayer' => ['nullable', 'string', 'max:1000'],
            'burial_place' => ['nullable', 'string', 'max:1000'],
            'condolence_place' => ['nullable', 'string', 'max:1000'],
            'condolence_times' => ['nullable', 'string', 'max:1000'],
            'family_consent' => ['nullable', 'boolean'],
            'sensitive_note' => ['nullable', 'string', 'max:2000'],
            ...self::placeRules(),
        ];
    }

    /**
     * Form times are community-time-zone wall clock; stored in UTC.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function prepareContentFields(array $fields): array
    {
        foreach (['publish_at', 'expires_at'] as $field) {
            if (array_key_exists($field, $fields)) {
                $fields[$field] = filled($fields[$field]) ? CarbonImmutable::parse($fields[$field], config('community.timezone'))->utc() : null;
            }
        }

        return $fields;
    }

    public function getContentTitleAttribute(): string
    {
        return $this->title;
    }

    /** Scheduled posts are invisible before publish_at; expired posts follow config('community.expired_news'). */
    protected function publicationWindow(Builder $query): Builder
    {
        $now = now();

        return $query->where(fn (Builder $start) => $start->whereNull($this->qualifyColumn('publish_at'))->orWhere($this->qualifyColumn('publish_at'), '<=', $now))
            ->when(config('community.expired_news') === 'hidden', fn (Builder $active) => $active->where(fn (Builder $end) => $end->whereNull($this->qualifyColumn('expires_at'))->orWhere($this->qualifyColumn('expires_at'), '>', $now)));
    }
}
