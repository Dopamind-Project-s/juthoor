<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An entity-scoped role: it covers the entity and its descendants, never its ancestors or siblings. */
#[Fillable(['community_entity_id', 'user_id', 'role'])]
class CommunityEntityManager extends Model
{
    public const ROLE_EDITOR = 'editor';

    public const ROLE_ADMIN = 'admin';

    /** @var array<string, string> */
    public const ROLES = [self::ROLE_EDITOR => 'محرر الكيان', self::ROLE_ADMIN => 'مدير الكيان'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CommunityEntity::class, 'community_entity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
