<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use DomainException;

class BranchFounderResolutionImporter
{
    public function __construct(private readonly AlZoubiDataset $dataset) {}

    /** @return array{people_created: int, relationships_created: int, branches_activated: int, unresolved: int} */
    public function import(FamilyTree $tree): array
    {
        $source = $tree->family->sources()->where('reference', SourceSeeder::DATASET_REFERENCE)->sole();
        $statistics = [
            'people_created' => 0,
            'relationships_created' => 0,
            'branches_activated' => 0,
            'unresolved' => count($this->unresolvedReviews()),
        ];
        $resolvedBranches = [];

        foreach ($this->resolutions() as $resolution) {
            $parentSlug = $resolution['parent_branch_slug'];

            if ($parentSlug !== null && ! isset($resolvedBranches[$parentSlug])) {
                throw new DomainException("Parent branch [{$parentSlug}] must be resolved first.");
            }

            foreach ($resolution['path'] as $row) {
                $this->validateEvidence($row);
                $person = Person::query()->firstOrNew([
                    'family_tree_id' => $tree->id,
                    'canonical_key' => $row['canonical_key'],
                ]);
                $wasNewPerson = ! $person->exists;
                $person->fill([
                    'first_name_ar' => $row['name_ar'],
                    'full_name_ar' => $row['name_ar'],
                    'notes' => 'مراجعة مؤسس فرع؛ مراجع المصدر: '.implode(', ', $row['source_refs']),
                    'status' => Person::STATUS_HISTORICAL,
                    'verification_status' => Source::VERIFICATION_VERIFIED,
                ])->save();
                $person->sources()->syncWithoutDetaching([
                    $source->id => ['relation_type' => 'branch_founder_path'],
                ]);
                $statistics['people_created'] += (int) $wasNewPerson;

                $father = Person::query()
                    ->where('family_tree_id', $tree->id)
                    ->where('canonical_key', $row['father_canonical_key'])
                    ->sole();
                $relationship = PersonRelationship::query()->firstOrNew([
                    'family_tree_id' => $tree->id,
                    'person_id' => $person->id,
                    'related_person_id' => $father->id,
                    'relationship_type' => PersonRelationship::TYPE_FATHER,
                ]);
                $wasNewRelationship = ! $relationship->exists;
                $relationship->fill([
                    'is_primary' => true,
                    'source_id' => $source->id,
                    'verification_status' => Source::VERIFICATION_VERIFIED,
                ])->save();
                $statistics['relationships_created'] += (int) $wasNewRelationship;
            }

            $founder = Person::query()
                ->where('family_tree_id', $tree->id)
                ->where('canonical_key', $resolution['founder_canonical_key'])
                ->sole();
            $branch = Branch::query()
                ->where('family_tree_id', $tree->id)
                ->where('slug', $resolution['branch_slug'])
                ->sole();
            $wasInactive = ! $branch->is_active;
            $branch->update([
                'root_person_id' => $founder->id,
                'verification_status' => $resolution['verification_status'],
                'is_active' => true,
                'description' => $resolution['notes'],
            ]);
            $statistics['branches_activated'] += (int) $wasInactive;
            $resolvedBranches[$resolution['branch_slug']] = $branch->id;
        }

        return $statistics;
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
