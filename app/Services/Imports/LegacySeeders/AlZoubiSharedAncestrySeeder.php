<?php

namespace App\Services\Imports\LegacySeeders;

use Database\Seeders\PersonAttributeSeeder;

use App\Models\Family;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Services\Genealogy\LivingStatusClassifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AlZoubiSharedAncestrySeeder extends Seeder
{
    public const SOURCE_REFERENCE = 'al-zoubi-shared-ancestry-user-2026-10-16';

    public const EXPANSION_KEY = 'ROOT-076';

    public static function key(int $position): string
    {
        return sprintf('ROOT-%03d', $position);
    }

    public static function edgeKey(int $childPosition): string
    {
        return self::key($childPosition).'-father-'.self::key($childPosition - 1);
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            $names = app(\App\Services\Imports\UnifiedGenealogyDataset::class)->snapshot()['legacy']['shared_ancestry'];
            $family = Family::query()->firstOrCreate(['slug' => FamilySeeder::SLUG], [
                'name_ar' => 'عشيرة الزعبي', 'is_active' => true,
            ]);
            Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            $tree = $family->familyTrees()->firstOrCreate(['slug' => FamilyTreeSeeder::SLUG], [
                'name_ar' => 'الشجرة الجامعة لعشيرة الزعبي', 'is_primary' => ! $family->familyTrees()->where('is_primary', true)->exists(), 'is_active' => true,
            ]);
            $tree = $family->familyTrees()->lockForUpdate()->findOrFail($tree->id);
            $existing = $tree->people()->whereIn('canonical_key', array_map(self::key(...), range(1, count($names))))->get()->keyBy('canonical_key');
            $conflicts = [];
            foreach ($names as $index => $name) {
                $key = self::key($index + 1);
                $person = $existing->get($key);
                if ($person && $person->full_name_ar !== $name) {
                    $conflicts[] = "$key: existing [{$person->full_name_ar}], supplied [$name]";
                }
                if (! $person && $tree->people()->where('full_name_ar', $name)
                    ->where(fn ($query) => $query->whereNull('canonical_key')->orWhereNotIn('canonical_key', array_keys($existing->all())))->exists()) {
                    $conflicts[] = "$key: [$name] exists without a reconciled stable key";
                }
                if ($person) {
                    foreach ($person->outgoingRelationships()->where('relationship_type', 'father')->get() as $edge) {
                        $father = $edge->relatedPerson;
                        if ($index === 0 || $father?->canonical_key !== self::key($index) || ! $edge->is_primary) {
                            $conflicts[] = "$key: existing father requires review";
                        }
                    }
                }
            }
            if ($tree->root_person_id && $tree->root_person_id !== $existing->get(self::key(1))?->id) {
                $conflicts[] = 'Existing tree root differs from ROOT-001';
            }
            if ($conflicts !== []) {
                throw new RuntimeException("Shared ancestry requires human reconciliation; no changes committed:\n".implode("\n", $conflicts));
            }

            $this->call(PersonAttributeSeeder::class);
            $attributes = PersonAttribute::query()->whereNull('family_id')->whereIn('key', ['prophet', 'companion', 'rashidun-caliph'])->get()->keyBy('key');
            if ($attributes->count() !== 3) {
                throw new RuntimeException('Reference attribute keys conflict with family-specific data.');
            }
            $source = $family->sources()->firstOrCreate(['reference' => self::SOURCE_REFERENCE], [
                'source_type' => 'user_submission',
                'title' => 'تسلسل الجذر المشترك المقدم للمراجعة — 2026-10-16',
                'notes' => 'نقل حرفي للأسماء والأوصاف المقدمة. لا يمثل تحققًا تاريخيًا. يوسف ROOT-076 نقطة التوسع المتفق عليها. لم تنشأ فروع.',
                'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
            ]);
            $titles = [
                46 => 'جد الهاشميين', 48 => 'عم النبي محمد', 50 => 'حفيد الرسول',
                63 => 'اول من لقب بالزعبي والجد الاول لعشيرة الزعبي', 76 => 'جد افخاد الزعبي بالسلط',
            ];
            $prophets = [1, 7, 10, 13, 19, 20];
            $previous = null;
            foreach ($names as $index => $name) {
                $position = $index + 1;
                $person = $tree->people()->firstOrCreate(['canonical_key' => self::key($position)], [
                    'first_name_ar' => $name, 'full_name_ar' => $name,
                    'title' => $titles[$position] ?? null,
                    'honorific' => in_array($position, $prophets, true) ? 'عليه السلام' : ($position === 49 ? 'كرم الله وجهه' : null),
                    'status' => Person::STATUS_HISTORICAL, 'is_living' => false,
                    'verification_status' => Source::VERIFICATION_PENDING_REVIEW, 'public_narrative' => true,
                    'living_status_basis' => LivingStatusClassifier::BASIS_SUPPLIED_ANCESTRY,
                ]);
                $ids = in_array($position, $prophets, true) ? [$attributes['prophet']->id] : [];
                if ($position === 49) {
                    $ids = [$attributes['companion']->id, $attributes['rashidun-caliph']->id];
                }
                $person->personAttributes()->syncWithoutDetaching($ids);
                $person->sources()->syncWithoutDetaching([$source->id => ['relation_type' => 'supplied_ancestry']]);
                $source->citations()->firstOrCreate(['person_id' => $person->id, 'field_key' => 'person', 'locator' => self::key($position)], [
                    'family_tree_id' => $tree->id, 'notes' => 'الاسم والأوصاف والصفات كما وردت؛ تحتاج مراجعة.',
                    'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
                ]);
                if ($previous) {
                    $edgeKey = self::edgeKey($position);
                    $keyed = $tree->personRelationships()->where('canonical_key', $edgeKey)->first();
                    if ($keyed && ($keyed->person_id !== $person->id || $keyed->related_person_id !== $previous->id || $keyed->relationship_type !== 'father')) {
                        throw new RuntimeException("Conflicting relationship key: $edgeKey");
                    }
                    $edge = $tree->personRelationships()->firstOrCreate([
                        'person_id' => $person->id, 'related_person_id' => $previous->id, 'relationship_type' => PersonRelationship::TYPE_FATHER,
                    ], [
                        'canonical_key' => $edgeKey, 'is_primary' => true,
                        'source_id' => $source->id, 'verification_status' => Source::VERIFICATION_PENDING_REVIEW, 'public_narrative' => true,
                    ]);
                    if ($edge->canonical_key !== null && $edge->canonical_key !== $edgeKey) {
                        throw new RuntimeException("Existing edge has another stable key: $edgeKey");
                    }
                    if ($edge->canonical_key === null) {
                        $edge->update(['canonical_key' => $edgeKey]);
                    }
                    $source->citations()->firstOrCreate(['person_relationship_id' => $edge->id, 'field_key' => 'father', 'locator' => $edgeKey], [
                        'family_tree_id' => $tree->id, 'notes' => 'ترتيب الأبوة بحسب التسلسل المقدم فقط.',
                        'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
                    ]);
                } elseif (! $tree->root_person_id) {
                    $tree->update(['root_person_id' => $person->id]);
                }
                $previous = $person;
            }
        });
        $this->command?->info('Shared ancestry saved for review. Expansion point: ROOT-076 (يوسف). No branches created or activated.');
    }
}
