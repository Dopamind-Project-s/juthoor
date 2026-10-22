<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

#[Fillable(['reviewers_only', 'user_id', 'family_id', 'auditable_type', 'auditable_id', 'action', 'before', 'after', 'reverted_from_id', 'reason'])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return ['reviewers_only' => 'boolean', 'before' => 'array', 'after' => 'array'];
    }

    /** Audit types holding membership or invitation details are visible to family administrators only. */
    public const ADMIN_ONLY_TYPES = [FamilyMembership::class, FamilyInvitation::class];

    public function canBeViewedBy(User $user): bool
    {
        if (! $this->family) {
            return $user->isSuperAdmin();
        }
        if (! Gate::forUser($user)->allows('view', $this->family)) {
            return false;
        }

        return Gate::forUser($user)->allows('review', $this->family)
            || (! $this->reviewers_only && ! in_array($this->auditable_type, self::ADMIN_ONLY_TYPES, true));
    }

    /** Hide reviewer-only and membership records from readers and editors of the family. */
    public function scopeVisibleToMember(Builder $query, User $user, Family $family): Builder
    {
        return Gate::forUser($user)->allows('review', $family)
            ? $query
            : $query->where('reviewers_only', false)->whereNotIn('auditable_type', self::ADMIN_ONLY_TYPES);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
