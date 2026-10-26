<?php

namespace App\Models;

use App\Models\Concerns\CommunityContent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * A position or achievement of an existing Person («شخصيات وإنجازات»). Categories are neutral editorial labels, never a
 * ranking. Visitors see a record only when the person is publicly visible; members only while the person is active.
 */
#[Fillable(['owner_entity_id', 'publication_status', 'verification_status', 'audience', 'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_by', 'sort_order', 'person_id', 'kind', 'category', 'title', 'organization', 'description', 'period_label', 'start_year', 'end_year'])]
class EntityAchievement extends Model
{
    use CommunityContent;

    /** @var array<string, string> */
    public const KINDS = ['position' => 'منصب', 'achievement' => 'إنجاز'];

    /** Non-exhaustive editorial categories from v2.0 chapter 8, in no order of importance. */
    public const CATEGORIES = [
        'scholar' => 'علماء', 'academic' => 'أكاديميون', 'professional' => 'مهنيون', 'public_figure' => 'شخصيات عامة',
        'judiciary' => 'قضاة', 'military' => 'عسكريون', 'sports' => 'رياضيون', 'business' => 'رواد أعمال',
        'social' => 'شخصيات اجتماعية', 'other' => 'أخرى',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return array<string, array<int, mixed>> */
    public static function contentRules(Family $family): array
    {
        return [
            'person_id' => ['required', 'integer', Rule::exists('people', 'id')->whereNull('merged_into_id')->whereIn('family_tree_id', $family->familyTrees()->pluck('id')->all())],
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'title' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'period_label' => ['nullable', 'string', 'max:120'],
            'start_year' => ['nullable', 'integer', 'between:-3000,2100'],
            'end_year' => ['nullable', 'integer', 'between:-3000,2100', 'gte:start_year'],
        ];
    }

    public function getContentTitleAttribute(): string
    {
        return $this->title;
    }

    protected function publicAudienceConstraint(Builder $query): Builder
    {
        return $query->whereHas('person', fn (Builder $person) => $person->publiclyVisible());
    }

    protected function memberAudienceConstraint(Builder $query): Builder
    {
        return $query->whereHas('person');
    }
}
