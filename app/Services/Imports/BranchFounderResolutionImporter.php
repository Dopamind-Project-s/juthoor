<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Services\Imports\LegacySeeders\SourceSeeder;
use DomainException;

class BranchFounderResolutionImporter
{
    public function __construct(private readonly AlZoubiDataset $dataset) {}

    /**
     * Creates missing founder-path people, father edges and first founder links only (SD-06). Existing people keep their
     * name, notes, status and verification; a branch whose founder, state or description an administrator already set
     * is preserved and reported. A path record whose name or father differs from the reviewed resolution is refused
     * before anything is written.
     *
     * @return array{people_created: int, relationships_created: int, branches_activated: int, unresolved: int, preserved: array<int, string>}
     */
    public function import(FamilyTree $tree): array
    {
        $source = $tree->family->sources()->where('reference', SourceSeeder::DATASET_REFERENCE)->sole();
        $statistics = [
            'people_created' => 0,
            'relationships_created' => 0,
            'branches_activated' => 0,
            'unresolved' => count($this->unresolvedReviews()),
            'preserved' => [],
        ];
        $conflicts = $this->conflicts($tree);
        if ($conflicts !== []) {
            throw new DomainException("Branch founder paths differ from existing records; resolve them through review before seeding:\n".implode("\n", $conflicts));
        }
        $resolvedBranches = [];

        foreach ($this->resolutions() as $resolution) {
            $parentSlug = $resolution['parent_branch_slug'];

            if ($parentSlug !== null && ! isset($resolvedBranches[$parentSlug])) {
                throw new DomainException("Parent branch [{$parentSlug}] must be resolved first.");
            }

            foreach ($resolution['path'] as $row) {
                $this->validateEvidence($row);
                $person = Person::query()->firstOrCreate([
                    'family_tree_id' => $tree->id,
                    'canonical_key' => $row['canonical_key'],
                ], self::personAttributes($row));
                $person->sources()->syncWithoutDetaching([
                    $source->id => ['relation_type' => 'branch_founder_path'],
                ]);
                $statistics['people_created'] += (int) $person->wasRecentlyCreated;

                $father = Person::query()
                    ->where('family_tree_id', $tree->id)
                    ->where('canonical_key', $row['father_canonical_key'])
                    ->sole();
                $relationship = PersonRelationship::query()->firstOrCreate([
                    'family_tree_id' => $tree->id,
                    'person_id' => $person->id,
                    'related_person_id' => $father->id,
                    'relationship_type' => PersonRelationship::TYPE_FATHER,
                ], [
                    'is_primary' => true,
                    'source_id' => $source->id,
                    'verification_status' => Source::VERIFICATION_VERIFIED,
                ]);
                $statistics['relationships_created'] += (int) $relationship->wasRecentlyCreated;
            }

            $founder = Person::query()
                ->where('family_tree_id', $tree->id)
                ->where('canonical_key', $resolution['founder_canonical_key'])
                ->sole();
            $branch = Branch::query()
                ->where('family_tree_id', $tree->id)
                ->where('slug', $resolution['branch_slug'])
                ->sole();
            if ($branch->root_person_id === null) {
                $wasInactive = ! $branch->is_active;
                $branch->update([
                    'root_person_id' => $founder->id,
                    'verification_status' => $resolution['verification_status'],
                    'is_active' => true,
                    'description' => $resolution['notes'],
                ]);
                $statistics['branches_activated'] += (int) $wasInactive;
            } elseif ($branch->root_person_id !== $founder->id) {
                $statistics['preserved'][] = "{$resolution['branch_slug']}: founder set by an administrator (#{$branch->root_person_id}) kept instead of {$resolution['founder_canonical_key']}";
            }
            $resolvedBranches[$resolution['branch_slug']] = $branch->id;
        }

        return $statistics;
    }

    /**
     * Values a newly created founder-path record receives.
     *
     * @param  array<string, mixed>  $row
     * @return array{first_name_ar: string, full_name_ar: string, notes: string, status: string, verification_status: string}
     */
    public static function personAttributes(array $row): array
    {
        return [
            'first_name_ar' => $row['name_ar'],
            'full_name_ar' => $row['name_ar'],
            'notes' => 'مراجعة مؤسس فرع؛ مراجع المصدر: '.implode(', ', $row['source_refs']),
            'status' => Person::STATUS_HISTORICAL,
            'verification_status' => Source::VERIFICATION_VERIFIED,
        ];
    }

    /**
     * Existing founder-path records whose name (or recorded variant) or father contradicts the reviewed resolution.
     *
     * @return array<int, string>
     */
    public function conflicts(FamilyTree $tree): array
    {
        $rows = collect($this->resolutions())->flatMap(fn (array $resolution) => $resolution['path']);
        $people = Person::query()->where('family_tree_id', $tree->id)
            ->whereIn('canonical_key', $rows->flatMap(fn (array $row) => [$row['canonical_key'], $row['father_canonical_key']])->unique()->all())
            ->get()->keyBy('canonical_key');
        $conflicts = [];

        foreach ($rows as $row) {
            $person = $people->get($row['canonical_key']);
            if (! $person) {
                continue;
            }
            if ($person->full_name_ar !== $row['name_ar'] && ! in_array($row['name_ar'], $person->alternate_names ?? [], true)) {
                $conflicts[] = "{$row['canonical_key']}: existing [{$person->full_name_ar}], resolution [{$row['name_ar']}]";
            }
            $fatherId = $people->get($row['father_canonical_key'])?->id;
            $otherFathers = $person->outgoingRelationships()->where('relationship_type', PersonRelationship::TYPE_FATHER)
                ->when($fatherId, fn ($query) => $query->where('related_person_id', '!=', $fatherId))->count();
            if ($otherFathers > 0) {
                $conflicts[] = "{$row['canonical_key']}: existing father differs from [{$row['father_canonical_key']}]";
            }
        }

        return $conflicts;
    }

    /** @return array<int, array<string, mixed>> */
    public function resolutions(): array
    {
        return require resource_path('data/al-zoubi/branch-founder-resolution.php');
    }

    /** @return array<int, array<string, mixed>> */
    public function unresolvedReviews(): array
    {
        return require resource_path('data/al-zoubi/branch-founder-review.php');
    }

    /** @param array<string, mixed> $row */
    private function validateEvidence(array $row): void
    {
        if ($row['source_refs'] === []) {
            throw new DomainException("Founder path record [{$row['canonical_key']}] has no source evidence.");
        }

        foreach ($row['source_refs'] as $sourceRef) {
            $sourceNode = $this->dataset->sourceNode($sourceRef);

            if (! $sourceNode || ($sourceNode['resolved_name'] ?? null) !== $row['name_ar']) {
                throw new DomainException("Source [{$sourceRef}] does not support [{$row['canonical_key']}].");
            }
        }
    }
}
