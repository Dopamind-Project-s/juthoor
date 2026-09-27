<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name_ar', 'name_en', 'slug', 'short_description', 'location', 'is_active'])]
class Family extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function familyTrees(): HasMany
    {
        return $this->hasMany(FamilyTree::class);
    }

    public function primaryTree(): HasOne
    {
        return $this->hasOne(FamilyTree::class)->where('is_primary', true);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
