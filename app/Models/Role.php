<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A functional family role. System roles come from the permission catalog; custom roles are created by super admins.
 * A role customized by an administrator (customized_at) is never rewritten by the catalog synchronizer.
 */
#[Fillable(['key', 'scope', 'label_ar', 'description', 'is_system', 'catalog_version', 'customized_at', 'deprecated_at'])]
class Role extends Model
{
    public const ORIGIN_CATALOG = 'catalog';

    public const ORIGIN_MANUAL = 'manual';

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'customized_at' => 'datetime', 'deprecated_at' => 'datetime'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withPivot('origin')->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FamilyRoleAssignment::class);
    }

    public function scopeAssignable($query)
    {
        return $query->where('scope', 'family')->whereNull('deprecated_at');
    }

    /** @return array<int, string> */
    public function permissionKeys(): array
    {
        return $this->permissions->whereNull('deprecated_at')->pluck('key')->sort()->values()->all();
    }
}
