<?php

namespace App\Models;

use App\Services\ArabicNameNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['family_tree_id', 'canonical_key', 'first_name_ar', 'full_name_ar', 'full_name_en', 'gender', 'birth_date', 'birth_year', 'death_date', 'death_year', 'is_living', 'notes', 'title', 'honorific', 'status', 'verification_status'])]
class Person extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_HISTORICAL = 'historical';

    public const STATUS_UNRESOLVED = 'unresolved';

    protected static function booted(): void
    {
        static::saving(function (Person $person): void {
            $person->name_search = app(ArabicNameNormalizer::class)->normalize($person->full_name_ar);
        });
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'death_date' => 'date',
            'is_living' => 'boolean',
        ];
    }

    public function personAttributes(): BelongsToMany
    {
        return $this->belongsToMany(PersonAttribute::class, 'person_attribute_assignments')->withTimestamps();
    }

    public function getDisplayNameAttribute(): string
    {
        return collect([$this->full_name_ar, $this->honorific, $this->title])->filter()->implode(' — ');
    }

    public function displayAttributes()
    {
        return $this->personAttributes->filter(fn (PersonAttribute $attribute) => $attribute->is_active
            && ($attribute->family_id === null || $attribute->family_id === $this->familyTree->family_id))->values();
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class, 'person_id');
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

    /** Explicitly published submitted foundation; verification is never changed. */
    public function scopeTreeVisible($query)
    {
        return $query->where('status', self::STATUS_HISTORICAL)
            ->where(fn ($q) => $q->where('is_living', false)->orWhereNull('is_living'))
            ->where(fn ($q) => $q->whereIn('verification_status', ['verified', 'sourced'])
                ->orWhere(fn ($q) => $q->where('verification_status', 'pending_review')
                    ->whereIn('canonical_key', array_map(fn ($n) => sprintf('ROOT-%03d', $n), range(1, 79)))
                    ->whereHas('sources', fn ($source) => $source->where('reference', 'al-zoubi-shared-ancestry-user-2026-10-16'))
                    ->whereHas('familyTree.family', fn ($family) => $family->where('slug', 'al-zoubi'))));
    }

    public function scopePubliclyVisible($query)
    {
        return $query->verified()->where('status', self::STATUS_HISTORICAL)->where(fn ($query) => $query->where('is_living', false)->orWhereNull('is_living'));
    }
}
