<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recorded genealogical claim about a person (JTH-08, chapter 14). The person record and the father edge stay the
 * active genealogy; an assertion only affects them through an accepted change request.
 */
#[Fillable(['family_tree_id', 'person_id', 'fact_type', 'value', 'related_person_id', 'relation', 'state', 'verification_status', 'notes', 'import_key', 'created_by', 'reviewed_by', 'reviewed_at', 'review_reason'])]
class Assertion extends Model
{
    /**
     * Supported facts: person field it would update, or the father edge; privacy field for PersonPrivacy.
     *
     * @var array<string, array{label: string, field: ?string, privacy: ?string}>
     */
    public const FACTS = [
        'full_name' => ['label' => 'الاسم الكامل', 'field' => 'full_name_ar', 'privacy' => null],
        'alternate_name' => ['label' => 'تهجئة أو اسم بديل', 'field' => 'alternate_names', 'privacy' => null],
        'father' => ['label' => 'الأب', 'field' => null, 'privacy' => null],
        'birth_year' => ['label' => 'سنة الميلاد', 'field' => 'birth_year', 'privacy' => 'birth_year'],
        'death_year' => ['label' => 'سنة الوفاة', 'field' => 'death_year', 'privacy' => 'death_year'],
        'title' => ['label' => 'اللقب', 'field' => 'title', 'privacy' => null],
        'honorific' => ['label' => 'عبارة التكريم', 'field' => 'honorific', 'privacy' => null],
    ];

    /** How a claim relates to the displayed value; spelling normalisation is never verification. */
    public const RELATIONS = [
        'unclassified' => 'غير مصنفة بعد',
        'alternate_spelling' => 'تهجئة بديلة للشخص نفسه',
        'additional' => 'معلومة إضافية متوافقة',
        'conflicting' => 'رواية متعارضة',
    ];

    /** Lifecycle; verification_status keeps the existing vocabulary (unresolved = disputed). */
    public const STATES = [
        'proposed' => 'مسجلة — لم تُعتمد',
        'authoritative' => 'المعتمدة للعرض',
        'alternative' => 'رواية بديلة مقبولة',
        'rejected' => 'مرفوضة',
        'superseded' => 'مستبدلة',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function relatedPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'related_person_id');
    }

    public function familyTree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }

    public function getLabelAttribute(): string
    {
        return self::FACTS[$this->fact_type]['label'] ?? $this->fact_type;
    }

    /** The claimed value as text (the father's name for parentage claims). */
    public function getClaimTextAttribute(): string
    {
        return $this->fact_type === 'father' ? (string) $this->relatedPerson?->display_name : (string) $this->value;
    }
}
