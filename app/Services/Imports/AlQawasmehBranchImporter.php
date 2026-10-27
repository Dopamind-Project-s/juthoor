<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Citation;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\Source;
use App\Services\AuditLogger;
use App\Services\Genealogy\BranchIntegrityService;
use App\Services\Imports\LegacySeeders\FamilySeeder;
use App\Services\Imports\LegacySeeders\FamilyTreeSeeder;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AlQawasmehBranchImporter
{
    public const REFERENCE = 'qawasmeh:submission:2026-10-18';

    /**
     * Working decision of 2026-10-22: «ذيبان» is the provisional display spelling for this reference dataset and the
     * legacy «ذبيان» of the base Al-Zoubi dataset is kept as a recorded variant. It is not a historical verification.
     */
    public const SPELLING_DECISION = 'قرار عمل مؤقت 2026-10-22: «ذيبان» تهجئة العرض المؤقتة لمرجع القواسمة، و«ذبيان» تهجئة بديلة محفوظة. ليس تحققًا تاريخيًا.';

    /** @var array<string, array<int, string>> Legacy spellings an anchor may carry; reconciled in place on the same record. */
    public const ANCHOR_VARIANTS = ['ROOT-077' => ['ذبيان']];

    /**
     * Stable keys in the base Al-Zoubi dataset that hold a shared-ancestry anchor identity. AZ-RAHAYMEH-SULAYMAN is the
     * only سليمان with ROOT-077 as primary father, and base sources SRC-16/17/18 trace both the عبد القادر and the
     * زين العابدين lines through him. His own key is kept so the base seeders stay idempotent.
     *
     * @var array<string, string>
     */
    public const ANCHOR_ALIASES = ['ROOT-078' => 'AZ-RAHAYMEH-SULAYMAN'];

    public function manifest(): array
    {
        return json_decode(file_get_contents(resource_path('data/al-qawasmeh/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Explicit reviewed identity mappings may supply IDs for missing anchor keys only. */
    public function import(?array $manifest = null, array $reviewedAnchors = []): array
    {
        $manifest ??= $this->manifest();

        return DB::transaction(function () use ($manifest, $reviewedAnchors): array {
            $family = Family::query()->where('slug', FamilySeeder::SLUG)->first()
                ?? throw new DomainException('Qawasmeh: seed the Al-Zoubi family first (db:seed or AlZoubiSharedAncestrySeeder).');
            Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            $tree = $family->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->lockForUpdate()->sole();
            $root = $tree->people()->where('canonical_key', 'ROOT-001')->sole();
            if ($tree->root_person_id !== $root->id) {
                throw new DomainException('Qawasmeh: shared root conflict.');
            }
            $people = [];
            $aliased = [];
            foreach (['ROOT-077' => 'ذيبان', 'ROOT-078' => 'سليمان'] as $key => $name) {
                $person = $tree->people()->where('canonical_key', $key)->first();
                if (! $person && isset(self::ANCHOR_ALIASES[$key])) {
                    $person = $tree->people()->where('canonical_key', self::ANCHOR_ALIASES[$key])->first();
                    $aliased[$key] = $person;
                }
                if (! $person) {
                    $review = $reviewedAnchors[$key] ?? null;
                    if (! $review || empty($review['review_reference']) || empty($review['person_id'])) {
                        throw new DomainException("Qawasmeh: missing stable anchor $key; explicit reviewed identity required.");
                    }
                    $person = $tree->people()->findOrFail($review['person_id']);
                    if ($person->canonical_key !== null || $person->full_name_ar !== $name) {
                        throw new DomainException("Qawasmeh: reviewed anchor identity conflict $key.");
                    }
                    $before = app(AuditLogger::class)->snapshot($person);
                    $person->update(['canonical_key' => $key]);
                    app(AuditLogger::class)->record(null, $family, $person, 'qawasmeh_anchor_reconciled', $before, array_merge(app(AuditLogger::class)->snapshot($person), ['review_reference' => $review['review_reference']]));
                }
                if ($person->full_name_ar !== $name && in_array($person->full_name_ar, self::ANCHOR_VARIANTS[$key] ?? [], true)) {
                    $this->reconcileSpelling($person, $name, $family);
                }
                if ($person->full_name_ar !== $name) {
                    throw new DomainException("Qawasmeh: anchor identity conflict $key.");
                }
                $people[$key] = $person;
            }
            $anchorFathers = $people['ROOT-078']->outgoingRelationships()->where('relationship_type', 'father')->get();
            if ($anchorFathers->count() !== 1 || $anchorFathers->first()->related_person_id !== $people['ROOT-077']->id || ! $anchorFathers->first()->is_primary) {
                throw new DomainException('Qawasmeh: existing ذيبان → سليمان parentage conflict.');
            }
            // Validate the whole ancestry to the existing shared root, without changing its approval state.
            $current = $people['ROOT-077'];
            $visited = [];
            while ($current->id !== $root->id) {
                if (isset($visited[$current->id])) {
                    throw new DomainException('Qawasmeh: anchor ancestry cycle.');
                }
                $visited[$current->id] = true;
                $fathers = $current->outgoingRelationships()->where('relationship_type', 'father')->get();
                if ($fathers->count() !== 1 || ! $fathers->first()->is_primary) {
                    throw new DomainException('Qawasmeh: anchor does not have a unique shared-root path.');
                }
                $current = $tree->people()->findOrFail($fathers->first()->related_person_id);
            }
            $sources = ['submission' => $this->created(Source::query()->firstOrCreate(['family_id' => $family->id, 'reference' => self::REFERENCE], [
                'source_type' => 'document', 'title' => 'القواسمة — التسلسل المقدم 2026-10-18',
                'notes' => 'ادعاءات مقدمة للمراجعة؛ لا تمنح صفة مؤسس النظام أية صلاحية نسبية أو إدارية.',
                'verification_status' => 'pending_review',
            ]), $family)];
            foreach (array_filter($aliased) as $key => $person) {
                $this->cite($sources['submission'], $family, ['family_tree_id' => $tree->id, 'person_id' => $person->id, 'field_key' => 'anchor', 'locator' => 'anchor:'.$key],
                    "مرسى {$key} في التسلسل المقدم هو السجل القائم {$person->canonical_key} (الابن الوحيد المسمى سليمان لـ ROOT-077)؛ لم يُنشأ شخص جديد. قيد المراجعة.");
            }
            foreach ($manifest['sources'] as $row) {
                $path = SourceDocuments::path($row['file']);
                if (! is_file($path) || ! hash_equals($row['sha256'], hash_file('sha256', $path))) {
                    throw new DomainException('Qawasmeh: missing or changed attachment '.$row['file']);
                }
                $sources[$row['file']] = $this->created(Source::query()->firstOrCreate(['family_id' => $family->id, 'reference' => $row['reference']], [
                    'source_type' => 'document', 'title' => $row['file'], 'verification_status' => 'pending_review',
                    'notes' => 'Page/image 1; SHA256: '.$row['sha256'].'; visually inspected. No OCR text layer in PDF.',
                ]), $family);
                if (! str_contains($sources[$row['file']]->notes ?? '', $row['sha256'])) {
                    throw new DomainException('Qawasmeh: source identity conflict '.$row['file']);
                }
            }
            $counts = ['people_created' => 0, 'relationships_created' => 0, 'observations' => count($manifest['observations']), 'unresolved' => count($manifest['reviews'])];
            $seen = [];
            foreach ($manifest['people'] as $row) {
                $key = $row['key'];
                if (isset($seen[$key]) || ! preg_match('/^QW-\d{4}$/', $key) || ! isset($people[$row['father']], $sources[$row['source']]) || trim($row['name']) === '') {
                    throw new DomainException("Qawasmeh: invalid or duplicated positional identity $key.");
                }
                $seen[$key] = true;
                $father = $people[$row['father']];
                $source = $sources[$row['source']];
                $identity = json_encode(['key' => $key, 'name' => $row['name'], 'father' => $row['father']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $person = $tree->people()->where('canonical_key', $key)->first();
                $evidence = $source->citations()->where('family_tree_id', $tree->id)->where('locator', $row['locator'])->where('field_key', 'person')->first();
                if ($evidence && (! $person || $evidence->person_id !== $person->id || $evidence->notes !== $identity)) {
                    throw new DomainException("Qawasmeh: positional source identity conflict $key.");
                }
                if ($person && ! $evidence && $person->full_name_ar !== $row['name']) {
                    throw new DomainException("Qawasmeh: stable identity conflict $key.");
                }
                // Same-name siblings from another import require human reconciliation, never automatic adoption.
                if (! $person && $tree->people()->where('full_name_ar', $row['name'])->whereHas('outgoingRelationships', fn ($q) => $q->where('relationship_type', 'father')->where('related_person_id', $father->id))->exists()) {
                    throw new DomainException("Qawasmeh: unreconciled existing child $key.");
                }
                if (! $person) {
                    $person = $this->created($tree->people()->create([
                        'canonical_key' => $key, 'first_name_ar' => $row['name'], 'full_name_ar' => $row['name'],
                        'title' => $row['title'] ?? null, 'status' => 'unresolved', 'is_living' => null,
                        'verification_status' => 'pending_review',
                    ]), $family);
                    $counts['people_created']++;
                }
                $people[$key] = $person;
                $person->sources()->syncWithoutDetaching([$source->id => ['relation_type' => 'qawasmeh_import']]);
                $this->cite($source, $family, ['family_tree_id' => $tree->id, 'person_id' => $person->id, 'field_key' => 'person', 'locator' => $row['locator']], $identity);
                $edgeKey = $key.'-father-'.$row['father'];
                $keyed = $tree->personRelationships()->where('canonical_key', $edgeKey)->first();
                if ($keyed && ($keyed->person_id !== $person->id || $keyed->related_person_id !== $father->id || $keyed->relationship_type !== 'father' || ! $keyed->is_primary)) {
                    throw new DomainException("Qawasmeh: relationship stable key conflict $edgeKey.");
                }
                $fathers = $person->outgoingRelationships()->where('relationship_type', 'father')->get();
                if ($fathers->contains(fn ($edge) => $edge->related_person_id !== $father->id || ! $edge->is_primary || $edge->canonical_key !== $edgeKey)) {
                    throw new DomainException("Qawasmeh: parentage conflict $key.");
                }
                $edge = $fathers->first();
                if (! $edge) {
                    $edge = $this->created($tree->personRelationships()->create([
                        'canonical_key' => $edgeKey, 'person_id' => $person->id, 'related_person_id' => $father->id,
                        'relationship_type' => 'father', 'is_primary' => true, 'source_id' => $source->id, 'verification_status' => 'pending_review',
                    ]), $family);
                    $counts['relationships_created']++;
                }
                $this->cite($source, $family, ['family_tree_id' => $tree->id, 'person_relationship_id' => $edge->id, 'field_key' => 'father', 'locator' => $edgeKey], $row['locator'].'; confidence='.$row['confidence'].'; pending authorized review');
            }
            $branch = $tree->branches()->where('slug', 'al-qawasmeh')->first();
            $founder = $people[$manifest['founder']] ?? throw new DomainException('Qawasmeh: founder missing.');
            if ($founder->canonical_key !== 'QW-0003' || ($branch && $branch->root_person_id !== null && $branch->root_person_id !== $founder->id)) {
                throw new DomainException('Qawasmeh: branch founder conflict.');
            }
            if (! $branch) {
                $branch = $this->created($tree->branches()->create([
                    'slug' => 'al-qawasmeh', 'name_ar' => 'القواسمة', 'root_person_id' => $founder->id,
                    'branch_type' => Branch::TYPE_FAMILY_GROUP, 'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS,
                    'description' => 'قاسم مؤسس الفرع والفخذ؛ ادعاء قيد المراجعة.', 'is_active' => false, 'verification_status' => 'pending_review',
                ]), $family);
            } elseif ($branch->root_person_id === null) {
                $before = app(AuditLogger::class)->snapshot($branch);
                $branch->update(['root_person_id' => $founder->id]);
                app(AuditLogger::class)->record(null, $family, $branch, 'qawasmeh_founder_linked', $before, app(AuditLogger::class)->snapshot($branch));
            }
            // Existing hierarchy is validated by the model service, never overwritten by this importer.
            app(BranchIntegrityService::class)->validate($branch);
            $this->cite($sources['submission'], $family, ['family_tree_id' => $tree->id, 'person_id' => $founder->id, 'field_key' => 'notes', 'locator' => 'al-qawasmeh:founder'], 'قاسم مؤسس فرع القواسمة والفخذ؛ لا تفعيل تلقائي قبل المراجعة.');
            $attribute = PersonAttribute::query()->whereNull('family_id')->where('key', 'branch-founder')->sole();
            if (! $founder->personAttributes()->whereKey($attribute->id)->exists()) {
                $before = app(AuditLogger::class)->snapshot($founder);
                $founder->personAttributes()->attach($attribute->id);
                app(AuditLogger::class)->record(null, $family, $founder, 'qawasmeh_founder_attribute', $before, app(AuditLogger::class)->snapshot($founder));
            }
            foreach ($manifest['observations'] as $observation) {
                $this->cite($sources[$observation['file']], $family, ['family_tree_id' => $tree->id, 'field_key' => 'source_observation', 'locator' => $observation['key']], json_encode($observation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'unresolved');
            }
            foreach ($manifest['reviews'] as $review) {
                $this->cite($sources[$review['file']] ?? $sources['submission'], $family, ['family_tree_id' => $tree->id, 'field_key' => 'source_observation', 'locator' => 'review:'.$review['key']], json_encode($review, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'unresolved');
            }

            return $counts;
        });
    }

    /** Same record, same key and verification state; only the display spelling changes and the old one is kept. */
    private function reconcileSpelling(Person $person, string $spelling, Family $family): void
    {
        $before = app(AuditLogger::class)->snapshot($person);
        $legacy = $person->full_name_ar;
        $person->alternate_names = array_values(array_unique([...($person->alternate_names ?? []), $legacy]));
        $person->full_name_ar = $spelling;
        if ($person->first_name_ar === $legacy) {
            $person->first_name_ar = $spelling;
        }
        $person->save();
        app(AuditLogger::class)->record(null, $family, $person, 'spelling_reconciled', $before, app(AuditLogger::class)->snapshot($person))
            ->update(['reason' => self::SPELLING_DECISION]);
    }

    private function created(Model $record, Family $family): Model
    {
        if ($record->wasRecentlyCreated) {
            app(AuditLogger::class)->record(null, $family, $record, 'qawasmeh_imported', null, app(AuditLogger::class)->snapshot($record));
        }

        return $record;
    }

    private function cite(Source $source, Family $family, array $identity, string $notes, string $status = 'pending_review'): Citation
    {
        return $this->created($source->citations()->firstOrCreate($identity, ['notes' => $notes, 'verification_status' => $status]), $family);
    }
}
