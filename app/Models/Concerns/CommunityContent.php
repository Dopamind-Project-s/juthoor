<?php

namespace App\Models\Concerns;

use App\Models\CommunityEntity;
use App\Models\ContentCitation;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Governance shared by every community content module: owner entity and entity links (HasCommunityEntities),
 * publication (draft → submitted → published / archived), verification, audience, review and evidence.
 * Published public records are visible to everyone; published member records only to members of the owner's family;
 * restricted records never appear on pages and are reached only through administration (and the media route).
 */
trait CommunityContent
{
    use HasCommunityEntities;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /** @var array<string, string> */
    public const PUBLICATION_STATUSES = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_SUBMITTED => 'بانتظار المراجعة',
        self::STATUS_PUBLISHED => 'منشور',
        self::STATUS_ARCHIVED => 'مؤرشف',
    ];

    /** @var array<string, string> */
    public const AUDIENCES = ['public' => 'العامة', 'members' => 'أعضاء العائلة فقط', 'restricted' => 'مقيد (مدراء العائلة والكيان)'];

    /** @var array<string, string> */
    public const VERIFICATION_STATUSES = [
        Source::VERIFICATION_PENDING_REVIEW => 'مسجل دون تحقق',
        Source::VERIFICATION_SOURCED => 'مسند إلى مصدر',
        Source::VERIFICATION_VERIFIED => 'موثق',
        'unresolved' => 'محل خلاف',
    ];

    public function initializeCommunityContent(): void
    {
        $this->mergeCasts(['submitted_at' => 'datetime', 'reviewed_at' => 'datetime']);
    }

    public function citations(): MorphMany
    {
        return $this->morphMany(ContentCitation::class, 'citable');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        $memberEntities = $viewer?->is_active
            ? CommunityEntity::query()->whereIn('family_id', $viewer->familyMemberships()->select('family_id'))->select('id')
            : null;

        return $this->publicationWindow($query->where($this->qualifyColumn('publication_status'), self::STATUS_PUBLISHED))
            ->where(function (Builder $audience) use ($memberEntities): void {
                $audience->where(fn (Builder $public) => $this->publicAudienceConstraint($public->where($this->qualifyColumn('audience'), 'public')));
                if ($memberEntities) {
                    $audience->orWhere(fn (Builder $members) => $this->memberAudienceConstraint($members->whereIn($this->qualifyColumn('owner_entity_id'), $memberEntities)));
                }
            });
    }

    /** Time limits of a published record (e.g. scheduled publication and expiry), checked on every query. */
    protected function publicationWindow(Builder $query): Builder
    {
        return $query;
    }

    /** Extra conditions for showing a record to visitors (e.g. the linked person must be public). */
    protected function publicAudienceConstraint(Builder $query): Builder
    {
        return $query;
    }

    /** Extra conditions for showing a record to members of the owner's family. */
    protected function memberAudienceConstraint(Builder $query): Builder
    {
        return $query;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::PUBLICATION_STATUSES[$this->publication_status] ?? $this->publication_status;
    }

    public function getVerificationLabelAttribute(): string
    {
        return self::VERIFICATION_STATUSES[$this->verification_status] ?? $this->verification_status;
    }
}
