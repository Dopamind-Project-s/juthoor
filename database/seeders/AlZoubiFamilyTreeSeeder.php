<?php

namespace Database\Seeders;

use App\Models\Assertion;
use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Services\AuditLogger;
use App\Services\Imports\UnifiedGenealogyDataset;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Services\Imports\AlZoubiSeedDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AlZoubiFamilyTreeSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new DomainException('The consolidated genealogy seed proposal is local/testing only.');
        }
        $payload = app(UnifiedGenealogyDataset::class)->snapshot();
        $data = $payload['data'];
        app(UnifiedGenealogyDataset::class)->validate($data);
        $checksums = $payload['checksums'];
        $reviews = $payload['reviews'];
        DB::transaction(function () use ($data, $checksums, $reviews): void {
            $family = $this->createMissing(Family::query(), ['slug' => 'al-zoubi'], AlZoubiSeedDefaults::familyAttributes());
            Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            $tree = $this->createMissing($family->familyTrees(), ['slug' => 'main'], AlZoubiSeedDefaults::treeAttributes());
            $audit = function (Model $record) use ($family): Model {
                if ($record->wasRecentlyCreated) {
                    app(AuditLogger::class)->record(null, $family, $record, 'unified_seed_imported', null, app(AuditLogger::class)->snapshot($record));
                }

                return $record;
            };
            $sources = [];
            $source = function (string $file) use (&$sources, $family, $audit, $checksums) {
                return $sources[$file] ??= $audit($this->createMissing($family->sources(), ['reference' => 'unified:'.hash('sha256', $file)], [
                    'source_type' => str_starts_with($file, 'user:') ? 'user_submission' : 'document',
                    'title' => $file, 'verification_status' => 'verified',
                    'notes' => 'Canonical seed proposal / source observation. Seed classified as verified by administrator request; original evidence not independently re-reviewed. SHA256: '.($checksums[$file] ?? 'user submission'),
                ]));
            };
            $people = [];
            foreach ($data['people'] as $row) {
                $existing = $tree->people()->where('canonical_key', $row['key'])->first();
                if ($existing && $existing->full_name_ar !== $row['name']
                    && ! in_array($row['name'], $existing->alternate_names ?? [], true)
                    && ! $existing->sources()->where('reference', 'like', 'unified:%')->exists()) {
                    throw new DomainException('Unmanaged identity collision: '.$row['key']);
                }
                if (! $existing && $row['father']) {
                    $fatherId = $tree->people()->where('canonical_key', $row['father'])->value('id');
                    if ($fatherId && $tree->people()->sameName($row['name'])->whereHas('outgoingRelationships', fn ($query) => $query->where('relationship_type', 'father')->where('related_person_id', $fatherId))->exists()) {
                        throw new DomainException('Unreconciled same-name child: '.$row['key']);
                    }
                }
                $person = $audit($this->createMissing($tree->people(), ['canonical_key' => $row['key']], [
                    'first_name_ar' => $row['name'], 'full_name_ar' => $row['name'], 'alternate_names' => $row['aliases'] ?? [],
                    'title' => $row['title'] ?? null,
                    'honorific' => in_array('prophet', $row['attributes'] ?? [], true) ? 'عليه السلام' : ($row['key'] === 'ROOT-049' ? 'كرم الله وجهه' : null),
                    'status' => (($row['is_living'] ?? null) === false ? Person::STATUS_HISTORICAL : Person::STATUS_ACTIVE),
                    'is_living' => $row['is_living'] ?? null, 'living_status_basis' => $row['living_status_basis'] ?? null,
                    'public_narrative' => true, 'verification_status' => 'verified',
                ]));
                // Apply the requested seed approval/public name display without changing living status or field privacy.
                $before = app(AuditLogger::class)->snapshot($person);
                $person->fill(['status' => ($person->is_living === false ? Person::STATUS_HISTORICAL : Person::STATUS_ACTIVE), 'verification_status' => 'verified', 'public_narrative' => true]);
                if ($person->isDirty(['status', 'verification_status', 'public_narrative'])) {
                    $person->save();
                    app(AuditLogger::class)->record(null, $family, $person, 'seed_status_updated', $before, app(AuditLogger::class)->snapshot($person));
                }
                // Preserve names, privacy and administrator customizations.
                $people[$row['key']] = $person;
                foreach ($row['sources'] as $evidence) {
                    $s = $source($evidence['file']);
                    $person->sources()->syncWithoutDetaching([$s->id => ['relation_type' => 'unified_seed']]);
                    $audit($this->createMissing($s->citations(), ['family_tree_id' => $tree->id, 'person_id' => $person->id, 'field_key' => 'person', 'locator' => $evidence['locator']], [
                        'notes' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'verification_status' => 'verified',
                    ]));
                }
                if ($person->wasRecentlyCreated) {
                    $attributeIds = PersonAttribute::query()->whereNull('family_id')->whereIn('key', $row['attributes'] ?? [])->pluck('id');
                    $person->personAttributes()->syncWithoutDetaching($attributeIds);
                }
            }
            if ($tree->root_person_id && $tree->root_person_id !== $people[$data['root']]->id) {
                throw new DomainException('Existing tree root requires reconciliation.');
            }
            if (! $tree->root_person_id) {
                $tree->update(['root_person_id' => $people[$data['root']]->id]);
            }
            foreach ($data['people'] as $row) {
                $person = $people[$row['key']];
                $fathers = $person->outgoingRelationships()->where('relationship_type', 'father')->get();
                if ($fathers->contains(fn ($edge) => ! $row['father'] || $edge->related_person_id !== $people[$row['father']]->id || ! $edge->is_primary)) {
                    throw new DomainException('Existing father requires reconciliation: '.$row['key']);
                }
                if (! $row['father']) {
                    continue;
                }
                $edgeKey = $row['key'].'-father-'.$row['father'];
                $keyed = $tree->personRelationships()->where('canonical_key', $edgeKey)->first();
                if ($keyed && ($keyed->person_id !== $person->id || $keyed->related_person_id !== $people[$row['father']]->id || $keyed->relationship_type !== 'father')) {
                    throw new DomainException('Relationship key collision: '.$edgeKey);
                }
                $firstSource = $source($row['sources'][0]['file']);
                $edge = $audit($this->createMissing($tree->personRelationships(), [
                    'person_id' => $person->id, 'related_person_id' => $people[$row['father']]->id, 'relationship_type' => 'father',
                ], ['canonical_key' => $edgeKey, 'is_primary' => true, 'source_id' => $firstSource->id,
                    'verification_status' => 'verified', 'public_narrative' => true]));
                foreach ($row['sources'] as $evidence) {
                    $s = $source($evidence['file']);
                    $audit($this->createMissing($s->citations(), ['family_tree_id' => $tree->id, 'person_relationship_id' => $edge->id, 'field_key' => 'father', 'locator' => $evidence['locator']], [
                        'notes' => json_encode($evidence + ['father_key' => $row['father']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'verification_status' => 'verified',
                    ]));
                }
            }
            $entities = [];
            $clan = $audit($this->createMissing(CommunityEntity::query(), ['family_id' => $family->id, 'slug' => 'al-zoubi'], [
                'entity_type' => 'clan', 'name_ar' => 'عشيرة الزعبي', 'family_tree_id' => $tree->id, 'publication_status' => 'published',
            ]));
            $branches = [];
            foreach ($data['branches'] as $row) {
                $founder = $row['founder'] ? $people[$row['founder']] : null;
                $branch = $audit($this->createMissing($tree->branches(), ['slug' => $row['slug']], [
                    'name_ar' => $row['name'], 'root_person_id' => $founder?->id,
                    'parent_branch_id' => $row['parent'] ? $branches[$row['parent']]->id : null,
                    'branch_type' => $row['type'], 'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS,
                    'is_active' => true, 'verification_status' => 'verified',
                ]));
                if ($branch->root_person_id !== $founder?->id) {
                    throw new DomainException('Existing founder requires reconciliation: '.$row['slug']);
                }
                $branches[$row['slug']] = $branch;
                $entities[$row['slug']] = $audit($this->createMissing(CommunityEntity::query(), ['family_id' => $family->id, 'slug' => $row['slug']], [
                    'name_ar' => $row['name'], 'family_tree_id' => $tree->id, 'branch_id' => $branch->id, 'entity_type' => $row['type'],
                    'parent_id' => $row['parent'] ? $entities[$row['parent']]->id : $clan->id, 'publication_status' => 'published',
                ]));
            }
            foreach ($reviews as $review) {
                $s = $source($review['source']);
                $citation = $audit($this->createMissing($s->citations(), ['family_tree_id' => $tree->id, 'field_key' => 'source_observation', 'locator' => 'unified-review:'.hash('sha256', $review['key'])], [
                    'notes' => json_encode($review, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'verification_status' => 'unresolved',
                ]));
                if (isset($review['person_key'], $people[$review['person_key']])) {
                    $claim = $audit($this->createMissing(Assertion::query(), ['family_tree_id' => $tree->id, 'import_key' => 'unified:'.$review['key']], [
                        'person_id' => $people[$review['person_key']]->id, 'fact_type' => 'alternate_name', 'value' => $review['original_name'],
                        'state' => 'proposed', 'relation' => 'conflicting', 'verification_status' => 'pending_review', 'notes' => $review['reason'],
                    ]));
                    if (! $citation->assertion_id) {
                        $citation->update(['assertion_id' => $claim->id]);
                    }
                }
            }
            $this->command?->info('Unified Al-Zoubi seed: '.$tree->people()->count().' people; '.$tree->personRelationships()->count().' father relationships; '.count($reviews).' private review observations.');
        });
    }

    /**
     * The family lock serializes seed imports. Avoid createOrFirst's per-row savepoints, which retain thousands of
     * PostgreSQL transaction locks. A concurrent conflicting insert fails and rolls back the entire import safely.
     */
    private function createMissing(Builder|Relation $query, array $identity, array $values = []): Model
    {
        $record = $query->firstOrNew($identity, $values);
        if (! $record->exists) {
            $record->save();
        } else {
            $classification = array_intersect_key($values, array_flip(['verification_status', 'is_active', 'publication_status']));
            if ($classification !== []) {
                $before = app(AuditLogger::class)->snapshot($record);
                $record->fill($classification);
                if ($record->isDirty(array_keys($classification))) {
                    $record->save();
                    $family = isset($record->family_id) ? Family::find($record->family_id) : ($record->family_tree_id ? \App\Models\FamilyTree::find($record->family_tree_id)?->family : null);
                    app(AuditLogger::class)->record(null, $family, $record, 'seed_approved', $before, app(AuditLogger::class)->snapshot($record));
                }
            }
        }

        return $record;
    }
}
