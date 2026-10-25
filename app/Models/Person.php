<?php

namespace App\Models;

use App\Services\ArabicNameNormalizer;
use App\Services\Genealogy\LivingStatusClassifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['family_tree_id', 'canonical_key', 'first_name_ar', 'full_name_ar', 'full_name_en', 'gender', 'birth_date', 'birth_year', 'death_date', 'death_year', 'is_living', 'notes', 'title', 'honorific', 'status', 'verification_status', 'alternate_names', 'public_narrative', 'living_status_basis', 'field_visibility'])]
class Person extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_HISTORICAL = 'historical';

    public const STATUS_UNRESOLVED = 'unresolved';

    protected static function booted(): void
    {
        static::saving(function (Person $person): void {
            $normalizer = app(ArabicNameNormalizer::class);
            $person->name_search = $normalizer->normalize($person->full_name_ar);
            $variants = collect($person->alternate_names ?? [])->map(fn ($name) => $normalizer->normalize((string) $name))->filter()->unique()->values();
            $person->alternate_name_search = $variants->isEmpty() ? null : '|'.$variants->implode('|').'|';
            // Provenance of the tri-state living status: a value chosen by a user is "recorded"; unknown has no basis.
            if ($person->isDirty('is_living') && ! $person->isDirty('living_status_basis')) {
                $person->living_status_basis = $person->is_living === null ? null : LivingStatusClassifier::BASIS_RECORDED;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'death_date' => 'date',
            'is_living' => 'boolean',
            'alternate_names' => 'array',
            'public_narrative' => 'boolean',
            'field_visibility' => 'array',
        ];
    }

    /** Arabic-normalized partial match on the display name or any recorded spelling variant. */
    public function scopeNameMatches($query, string $term)
    {
        $normalized = app(ArabicNameNormalizer::class)->normalize($term);

        return $query->where(fn ($query) => $query->where('name_search', 'like', '%'.$normalized.'%')
            ->orWhere('alternate_name_search', 'like', '%'.$normalized.'%'));
    }

    /** Exact normalized match on the display name or a recorded variant (duplicate detection). */
    public function scopeSameName($query, string $name)
    {
        $normalized = app(ArabicNameNormalizer::class)->normalize($name);

        return $query->where(fn ($query) => $query->where('name_search', $normalized)
            ->orWhere('alternate_name_search', 'like', '%|'.$normalized.'|%'));
    }

    public function personAttributes(): BelongsToMany
    {
        return $this->belongsToMany(PersonAttribute::class, 'person_attribute_assignments')->withTimestamps();
    }

    public const LIVING_STATUS_LABELS = ['living' => 'على قيد الحياة', 'deceased' => 'متوفى', 'unknown' => 'غير معروف'];

    /** living | deceased | unknown — unknown is never treated as deceased. */
    public function getLivingStatusAttribute(): string
    {
        return match ($this->is_living) {
            true => 'living',
            false => 'deceased',
            default => 'unknown',
        };
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

    public function assertions(): HasMany
    {
        return $this->hasMany(Assertion::class);
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

    /**
     * The single public-display rule for people on every public surface (tree, graph, focus, person pages, search,
     * branch listings): historical, explicitly deceased (unknown is private), and either verified/sourced or a submitted narrative that
     * an administrator explicitly published while it remains pending review. Verification is never raised by this.
     */
    public function scopePubliclyVisible($query)
    {
        return $query->where('status', self::STATUS_HISTORICAL)
            ->where('is_living', false)
            ->where(fn ($query) => $query->verified()
                ->orWhere(fn ($query) => $query->where('verification_status', Source::VERIFICATION_PENDING_REVIEW)->where('public_narrative', true)));
    }

    /** Stricter than public display: verified historical records only (shared-ancestry linking, JTH-05). */
    public function scopeVerifiedHistorical($query)
    {
        return $query->verified()->where('status', self::STATUS_HISTORICAL)->where('is_living', false);
    }
}
