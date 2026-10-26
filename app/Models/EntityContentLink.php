<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Attaches one content record to an entity without copying it. A subject link says what the record is about and
 * rolls up to ancestor pages; an audience link addresses the record to the entity and every descendant page.
 */
#[Fillable(['community_entity_id', 'linkable_type', 'linkable_id', 'relation'])]
class EntityContentLink extends Model
{
    public const RELATION_SUBJECT = 'subject';

    public const RELATION_AUDIENCE = 'audience';

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CommunityEntity::class, 'community_entity_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
