<?php

namespace App\Models;

use App\Services\Genealogy\FamilyTreeIntegrityService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['family_id', 'root_person_id', 'name_ar', 'name_en', 'slug', 'description', 'is_primary', 'is_active'])]
class FamilyTree extends Model
{
    protected static function booted(): void
    {
        static::saving(function (FamilyTree $tree): void {
            app(FamilyTreeIntegrityService::class)->validate($tree);
        });

        static::saved(function (FamilyTree $tree): void {
            if ($tree->is_primary) {
                static::query()
                    ->where('family_id', $tree->family_id)
                    ->whereKeyNot($tree->getKey())
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
        });
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_active' => 'boolean'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function rootPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'root_person_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function assertions(): HasMany
    {
        return $this->hasMany(Assertion::class);
    }

    public function personRelationships(): HasMany
    {
        return $this->hasMany(PersonRelationship::class);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
