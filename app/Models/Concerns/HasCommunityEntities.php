<?php

namespace App\Models\Concerns;

use App\Models\CommunityEntity;
use App\Models\EntityContentLink;
use App\Models\User;
use App\Services\Community\EntityContentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Contract for content modules shown on entity pages. The table carries `owner_entity_id` (the administrative owner);
 * subject and audience entities live in `entity_content_links`; the module defines its own publication and audience
 * rules in `scopeVisibleTo`.
 */
trait HasCommunityEntities
{
    abstract public function scopeVisibleTo(Builder $query, ?User $viewer): Builder;

    public function ownerEntity(): BelongsTo
    {
        return $this->belongsTo(CommunityEntity::class, 'owner_entity_id');
    }

    public function entityLinks(): MorphMany
    {
        return $this->morphMany(EntityContentLink::class, 'linkable');
    }

    /**
     * @param  array<int, int>|null  $eligibleIds
     */
    public function scopeOnEntityPage(Builder $query, CommunityEntity $entity, string $mode, ?User $viewer, ?array $eligibleIds = null): Builder
    {
        return app(EntityContentScope::class)->apply($query, $entity, $mode, $eligibleIds)->visibleTo($viewer);
    }

    /** Editing follows the owner entity only: appearing on a page managed by someone grants that manager nothing. */
    public function isEditableBy(User $user): bool
    {
        return $this->ownerEntity !== null && $user->can('update', $this->ownerEntity);
    }
}
