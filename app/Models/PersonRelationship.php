<?php

namespace App\Models;

use App\Services\Genealogy\RelationshipIntegrityService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['family_tree_id', 'canonical_key', 'person_id', 'related_person_id', 'relationship_type', 'is_primary', 'source_id', 'verification_status', 'public_narrative'])]
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
        return ['is_primary' => 'boolean', 'public_narrative' => 'boolean'];
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class, 'person_relationship_id');
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

    /** Public-display rule for father edges; mirrors Person::scopePubliclyVisible. */
    public function scopePubliclyVisible($query)
    {
        return $query->where('is_primary', true)
            ->where(fn ($query) => $query->whereIn('verification_status', [Source::VERIFICATION_VERIFIED, Source::VERIFICATION_SOURCED])
                ->orWhere(fn ($query) => $query->where('verification_status', Source::VERIFICATION_PENDING_REVIEW)->where('public_narrative', true)));
    }

    public function scopeVerified($query)
    {
        return $query->where('is_primary', true)->whereIn('verification_status', [
            Source::VERIFICATION_VERIFIED,
            Source::VERIFICATION_SOURCED,
        ]);
    }
}
