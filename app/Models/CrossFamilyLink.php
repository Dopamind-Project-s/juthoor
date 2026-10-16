<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['left_person_id', 'right_person_id', 'left_family_id', 'right_family_id', 'kind', 'status', 'claim', 'submitted_by', 'reviewed_by', 'reviewed_at', 'review_reason', 'dependency_hash', 'invalidation_reason'])]
class CrossFamilyLink extends Model
{
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function leftPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'left_person_id');
    }

    public function rightPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'right_person_id');
    }

    public function leftFamily(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'left_family_id');
    }

    public function rightFamily(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'right_family_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class);
    }

    public function canView(User $user): bool
    {
        return $user->is_active && ($user->isSuperAdmin() ||
            ($this->leftFamily?->is_active && $user->roleFor($this->left_family_id) === 'admin') ||
            ($this->rightFamily?->is_active && $user->roleFor($this->right_family_id) === 'admin'));
    }
}
