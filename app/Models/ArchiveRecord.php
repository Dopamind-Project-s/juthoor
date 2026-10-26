<?php

namespace App\Models;

use App\Models\Concerns\CommunityContent;
use App\Models\Concerns\HasMediaAttachments;
use App\Models\Concerns\HasUsageRights;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * A heritage archive entry (JTH-15): a genealogy chart, manuscript, document, photograph, written account or oral
 * history, with its files attached from the media library. Uploading never verifies it; visitors see an entry about
 * a person only when that person is publicly visible.
 */
#[Fillable(['owner_entity_id', 'publication_status', 'verification_status', 'audience', 'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_by', 'sort_order', 'rights_status', 'rights_holder', 'rights_note', 'title', 'description', 'category', 'original_source', 'period_label', 'start_year', 'end_year', 'contributor', 'person_id'])]
class ArchiveRecord extends Model
{
    use CommunityContent, HasMediaAttachments, HasUsageRights;

    /** @var array<string, string> */
    public const CATEGORIES = [
        'genealogy_chart' => 'مشجرة نسب تاريخية',
        'manuscript' => 'مخطوطة',
        'book_excerpt' => 'كتاب أو مقتطف',
        'document' => 'وثيقة تاريخية',
        'photograph' => 'صورة تاريخية',
        'written_account' => 'رواية نسب مكتوبة',
        'oral_history' => 'رواية شفوية مسجلة',
        'other' => 'مادة تراثية أخرى',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return array<string, array<int, mixed>> */
    public static function contentRules(Family $family): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'original_source' => ['nullable', 'string', 'max:2000'],
            'period_label' => ['nullable', 'string', 'max:120'],
            'start_year' => ['nullable', 'integer', 'between:-3000,2100'],
            'end_year' => ['nullable', 'integer', 'between:-3000,2100', 'gte:start_year'],
            'contributor' => ['nullable', 'string', 'max:255'],
            'person_id' => ['nullable', 'integer', Rule::exists('people', 'id')->whereNull('merged_into_id')->whereIn('family_tree_id', $family->familyTrees()->pluck('id')->all())],
            ...self::rightsRules(),
        ];
    }

    public function getContentTitleAttribute(): string
    {
        return $this->title;
    }

    protected function publicAudienceConstraint(Builder $query): Builder
    {
        return $query->where(fn (Builder $person) => $person->whereNull('person_id')->orWhereHas('person', fn (Builder $visible) => $visible->publiclyVisible()));
    }

    protected function memberAudienceConstraint(Builder $query): Builder
    {
        return $query->where(fn (Builder $person) => $person->whereNull('person_id')->orWhereHas('person'));
    }
}
