<?php

namespace App\Services\Genealogy;

use App\Models\Branch;
use App\Models\Person;
use DomainException;

class BranchIntegrityService
{
    public function __construct(private readonly GenealogyTraversalService $traversal) {}

    public function validate(Branch $branch): void
    {
        if ($branch->is_active && $branch->root_person_id === null) {
            throw new DomainException('An active branch must have a confirmed root person.');
        }

        $root = $branch->root_person_id === null
            ? null
            : Person::query()->find($branch->root_person_id);

        if ($root && $root->family_tree_id !== (int) $branch->family_tree_id) {
            throw new DomainException('A branch founder must belong to the branch family tree.');
        }

        if ($branch->root_person_id !== null && ! $root) {
            throw new DomainException('A branch founder must exist.');
        }

        if ($branch->parent_branch_id === null) {
            return;
        }

        if ($branch->exists && (int) $branch->parent_branch_id === (int) $branch->getKey()) {
            throw new DomainException('A branch cannot be its own parent branch.');
        }

        $parent = Branch::query()->find($branch->parent_branch_id);

        if (! $parent || $parent->family_tree_id !== (int) $branch->family_tree_id) {
            throw new DomainException('A parent branch must belong to the same family tree.');
        }

        $this->assertHierarchyIsAcyclic($branch, $parent);

        if ($root && $parent->root_person_id !== null
            && ! $this->traversal->isDescendantOf($root->id, $parent->root_person_id)) {
            throw new DomainException('A nested branch founder must descend from the parent branch founder.');
        }
    }

    private function assertHierarchyIsAcyclic(Branch $branch, Branch $parent): void
    {
        $parents = Branch::query()
            ->where('family_tree_id', $branch->family_tree_id)
            ->whereNotNull('parent_branch_id')
            ->pluck('parent_branch_id', 'id')
            ->all();
        $currentId = $parent->id;
        $visited = [];

        while ($currentId !== null) {
            if (($branch->exists && $currentId === $branch->id) || isset($visited[$currentId])) {
                throw new DomainException('The parent branch would create a branch hierarchy cycle.');
            }

            $visited[$currentId] = true;
            $currentId = isset($parents[$currentId]) ? (int) $parents[$currentId] : null;
        }
    }
}
