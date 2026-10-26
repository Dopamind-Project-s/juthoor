<?php

namespace App\Models;

use App\Models\Concerns\CommunityContent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * A place of origin, settlement, migration or gathering for one or more entities. It describes a region of the
 * community, never a person's address, and carries no coordinates.
 */
#[Fillable(['owner_entity_id', 'publication_status', 'verification_status', 'audience', 'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_by', 'sort_order', 'name', 'country', 'governorate', 'city', 'era', 'kind', 'period_label', 'description'])]
class CommunityLocation extends Model
{
    use CommunityContent;

    /** @var array<string, string> */
    public const ERAS = ['historical' => 'تاريخي', 'current' => 'حالي'];

    /** @var array<string, string> */
    public const KINDS = ['origin' => 'منطقة أصل', 'settlement' => 'استقرار وإقامة', 'migration' => 'محطة هجرة', 'gathering' => 'منطقة تجمع'];

    /** @return array<string, array<int, mixed>> */
    public static function contentRules(Family $family): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:120'],
            'governorate' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'era' => ['required', Rule::in(array_keys(self::ERAS))],
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'period_label' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function getContentTitleAttribute(): string
    {
        return $this->name;
    }

    public function getPlaceLabelAttribute(): string
    {
        return collect([$this->city, $this->governorate, $this->country])->filter()->implode('، ');
    }
}
