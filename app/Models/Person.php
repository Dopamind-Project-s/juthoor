<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['family_tree_id', 'canonical_key', 'first_name_ar', 'full_name_ar', 'full_name_en', 'gender', 'birth_date', 'birth_year', 'death_date', 'death_year', 'is_living', 'notes', 'status', 'verification_status'])]
class Person extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_HISTORICAL = 'historical';

    public const STATUS_UNRESOLVED = 'unresolved';

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'death_date' => 'date',
            'is_living' => 'boolean',
        ];
    }

    public function familyTree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class);
    }

    public function outgoingRelationships(): HasMany
    {
        return $this->hasMany(PersonRelationship::class, 'person_id');
    }

    public function incomingRelationships(): HasMany
    {
        return $this->hasMany(PersonRelationship::class, 'related_person_id');
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'person_sources')
            ->withPivot(['id', 'relation_type', 'notes'])
            ->withTimestamps();
    }

    public function foundedBranches(): HasMany
    {
        return $this->hasMany(Branch::class, 'root_person_id');
    }

    public function scopeVerified($query)
    {
        return $query->whereIn('verification_status', [
            Source::VERIFICATION_VERIFIED,
            Source::VERIFICATION_SOURCED,
        ]);
    }
}
