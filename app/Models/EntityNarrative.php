<?php

namespace App\Models;

use App\Models\Concerns\CommunityContent;
use App\Models\Concerns\HasMediaAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * A historical account on an entity page (overview, origins, founding, migration, periods, milestones). Competing
 * accounts are separate records marked «رواية بديلة»; none is verified because it exists in a dataset or document.
 */
#[Fillable(['owner_entity_id', 'publication_status', 'verification_status', 'audience', 'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_by', 'sort_order', 'section', 'account', 'title', 'body', 'period_label', 'start_year', 'end_year'])]
class EntityNarrative extends Model
{
    use CommunityContent, HasMediaAttachments;

    /** @var array<string, string> */
    public const SECTIONS = [
        'overview' => 'مقدمة ونظرة عامة',
        'origins' => 'الأصول التاريخية',
        'founding' => 'روايات التأسيس',
        'migration' => 'الهجرات والانتقال',
        'period' => 'الحقب التاريخية',
        'milestone' => 'محطات مهمة',
    ];

    /** @var array<string, string> */
    public const ACCOUNTS = ['primary' => 'الرواية المعروضة', 'alternative' => 'رواية بديلة'];

    /** @return array<string, array<int, mixed>> */
    public static function contentRules(Family $family): array
    {
        return [
            'section' => ['required', Rule::in(array_keys(self::SECTIONS))],
            'account' => ['required', Rule::in(array_keys(self::ACCOUNTS))],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'period_label' => ['nullable', 'string', 'max:120'],
            'start_year' => ['nullable', 'integer', 'between:-3000,2100'],
            'end_year' => ['nullable', 'integer', 'between:-3000,2100', 'gte:start_year'],
        ];
    }

    public function getContentTitleAttribute(): string
    {
        return $this->title;
    }
}
