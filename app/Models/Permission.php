<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A catalog permission; rows are maintained by RoleCatalogSynchronizer, never deleted while grants reference them. */
#[Fillable(['key', 'scope', 'label_ar', 'catalog_version', 'deprecated_at', 'replaced_by'])]
class Permission extends Model
{
    protected function casts(): array
    {
        return ['deprecated_at' => 'datetime'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('origin')->withTimestamps();
    }
}
