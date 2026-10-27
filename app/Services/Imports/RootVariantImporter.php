<?php

namespace App\Services\Imports;

use App\Models\Assertion;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\Source;
use App\Services\AuditLogger;
use App\Services\Imports\LegacySeeders\AlZoubiSharedAncestrySeeder;
use App\Services\Imports\LegacySeeders\FamilySeeder;
use App\Services\Imports\LegacySeeders\FamilyTreeSeeder;
use App\Services\Imports\LegacySeeders\SourceSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Records, without reconciling, where the two supplied Al-Zoubi root lists name a ROOT person differently from the
 * displayed name. Each difference becomes an unclassified claim citing its list; a spelling already recorded as a
 * variant (ROOT-077 ذبيان) becomes an accepted alternate spelling. People are never changed. Idempotent.
 */
class RootVariantImporter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{created: int, alternative: int, unchanged: int} */
    public function import(): array
    {
        return DB::transaction(function (): array {
            $family = Family::query()->where('slug', FamilySeeder::SLUG)->first()
                ?? throw new DomainException('Root variants: seed the Al-Zoubi family first.');
            $tree = $family->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->lockForUpdate()->sole();
            $counts = ['created' => 0, 'alternative' => 0, 'unchanged' => 0];
            foreach ($this->lists() as $list => [$reference, $title, $sourceType, $names]) {
                $source = null;
                foreach ($names as $key => $name) {
                    $person = $tree->people()->where('canonical_key', $key)->first();
                    if (! $person || $person->full_name_ar === $name) {
                        continue;
                    }
                    $importKey = "root-variant:{$list}:{$key}";
                    if ($tree->assertions()->where('import_key', $importKey)->exists()) {
                        $counts['unchanged']++;

                        continue;
                    }
                    $source ??= $family->sources()->firstOrCreate(['reference' => $reference], [
                        'source_type' => $sourceType, 'title' => $title, 'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
                        'notes' => 'أُنشئ لتوثيق اختلافات الأسماء في الجذر المشترك؛ وجوده لا يعني التحقق.',
                    ]);
                    $accepted = in_array($name, $person->alternate_names ?? [], true);
                    $assertion = $this->claim($tree, $person, $name, $importKey, $accepted, $list);
                    $citation = $assertion->citations()->create([
                        'source_id' => $source->id, 'family_tree_id' => $tree->id, 'field_key' => 'assertion', 'locator' => $key,
                        'notes' => 'الاسم كما ورد في القائمة عند المفتاح '.$key.'.', 'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
                    ]);
                    $this->audit->record(null, $family, $assertion, 'created', null, $this->audit->snapshot($assertion));
                    $this->audit->record(null, $family, $citation, 'created', null, $this->audit->snapshot($citation));
                    $counts[$accepted ? 'alternative' : 'created']++;
                }
            }

            return $counts;
        });
    }

    private function claim(FamilyTree $tree, Person $person, string $name, string $importKey, bool $accepted, string $list): Assertion
    {
        return $person->assertions()->create($accepted ? [
            'family_tree_id' => $tree->id, 'fact_type' => 'alternate_name', 'value' => $name, 'relation' => 'alternate_spelling',
            'state' => 'alternative', 'verification_status' => Source::VERIFICATION_PENDING_REVIEW, 'import_key' => $importKey,
            'notes' => 'تهجئة مسجلة بديلةً في سجل الشخص بقرار العمل المؤقت.', 'reviewed_at' => now(),
            'review_reason' => AlQawasmehBranchImporter::SPELLING_DECISION,
        ] : [
            'family_tree_id' => $tree->id, 'fact_type' => 'full_name', 'value' => $name, 'relation' => 'unclassified',
            'state' => 'proposed', 'verification_status' => Source::VERIFICATION_PENDING_REVIEW, 'import_key' => $importKey,
            'notes' => 'اختلاف بين '.($list === 'dataset' ? 'مجموعة البيانات المنسقة (الإصدار الرابع)' : 'التسلسل المقدم في 2026-10-16').' والاسم المعروض؛ لم يُصنف بعد (تهجئة أم رواية مختلفة).',
        ]);
    }

    /** @return array<string, array{string, string, string, array<string, string>}> */
    private function lists(): array
    {
        $dataset = collect(app(AlZoubiDataset::class)->readyRootRows())->pluck('name_ar', 'person_key')->all();
        $shared = [];
        foreach (app(\App\Services\Imports\UnifiedGenealogyDataset::class)->snapshot()['legacy']['shared_ancestry'] as $index => $name) {
            $shared[AlZoubiSharedAncestrySeeder::key($index + 1)] = $name;
        }

        return [
            'dataset' => [SourceSeeder::DATASET_REFERENCE, 'مجموعة بيانات نسب عشيرة الزعبي — الإصدار الرابع', 'genealogy_dataset', $dataset],
            'shared' => [AlZoubiSharedAncestrySeeder::SOURCE_REFERENCE, 'تسلسل الجذر المشترك المقدم للمراجعة — 2026-10-16', 'user_submission', $shared],
        ];
    }
}
