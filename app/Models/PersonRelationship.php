<?php

namespace App\Models;

use App\Services\Genealogy\RelationshipIntegrityService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_tree_id', 'person_id', 'related_person_id', 'relationship_type', 'is_primary', 'source_id', 'verification_status'])]
class PersonRelationship extends Model
{
    public const TYPE_FATHER = 'father';

    protected static function booted(): void
    {
        static::saving(function (PersonRelationship $relationship): void {
            app(RelationshipIntegrityService::class)->validate($relationship);
        });
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function familyTree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function relatedPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'related_person_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_primary', true)->whereIn('verification_status', [
            Source::VERIFICATION_VERIFIED,
            Source::VERIFICATION_SOURCED,
        ]);
    }
}
