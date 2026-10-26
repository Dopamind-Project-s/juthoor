<?php

namespace App\Models;

use App\Services\Community\CommunityEntityIntegrityService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tribe, clan, fakhd, sub-fakhd or branch/hamula page (v2.0 chapters 5–7): a content and administration unit, distinct
 * from the genealogical Branch it may point at. Levels may be skipped; a child is never placed above its parent's level.
 */
#[Fillable(['family_id', 'parent_id', 'entity_type', 'name_ar', 'name_en', 'slug', 'alternate_names', 'summary', 'description', 'family_tree_id', 'branch_id', 'publication_status', 'reviewed_at', 'reviewed_by', 'sort_order', 'population_estimate', 'population_estimate_year', 'population_estimate_source_id'])]
class CommunityEntity extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_PUBLISHED => 'منشورة',
        self::STATUS_ARCHIVED => 'مؤرشفة',
    ];

    /**
     * Hierarchy rank of each type, highest level first; the labels are the configurable branch levels.
     *
     * @var array<string, int>
     */
    public const RANKS = ['tribe' => 1, 'clan' => 2, 'fakhd' => 3, 'sub_fakhd' => 4, Branch::TYPE_FAMILY_GROUP => 5];

    /** Mirrors the column defaults so unsaved entities validate like stored ones. */
    protected $attributes = ['publication_status' => self::STATUS_DRAFT, 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (CommunityEntity $entity): void {
            app(CommunityEntityIntegrityService::class)->validate($entity);
        });
    }

    protected function casts(): array
    {
        return ['alternate_names' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function getTypeLabelAttribute(): string
    {
        return Branch::TYPES[$this->entity_type] ?? $this->entity_type;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->publication_status] ?? $this->publication_status;
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function familyTree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function populationEstimateSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'population_estimate_source_id');
    }

    public function managers(): HasMany
    {
        return $this->hasMany(CommunityEntityManager::class);
    }

    public function contentLinks(): HasMany
    {
        return $this->hasMany(EntityContentLink::class);
    }

    public function scopePublished($query)
    {
        return $query->where('publication_status', self::STATUS_PUBLISHED);
    }
}
