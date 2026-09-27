<?php

namespace App\Services\Genealogy;

use App\Models\Branch;
use App\Models\Person;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

class BranchMembershipService
{
    public function __construct(private readonly GenealogyTraversalService $traversal) {}

    public function contains(Branch|int $branch, Person|int $person): bool
    {
        $branch = $this->branch($branch);
        $personId = $person instanceof Person ? $person->id : $person;

        return in_array($personId, $this->memberIds($branch), true);
    }

    /** @return Collection<int, Person> */
    public function members(Branch|int $branch, ?int $depth = null): Collection
    {
        $branch = $this->branch($branch);

        return Person::query()
            ->where('family_tree_id', $branch->family_tree_id)
            ->whereKey($this->memberIds($branch, $depth))
            ->get();
    }

    public function validateNestedFounder(Branch $branch): void
    {
        app(BranchIntegrityService::class)->validate($branch);
    }

    /** @return array<int, int> */
    private function memberIds(Branch $branch, ?int $depth = null): array
    {
        if ($branch->membership_mode !== Branch::MEMBERSHIP_DESCENDANTS) {
            throw new DomainException("Unsupported branch membership mode [{$branch->membership_mode}].");
        }

        if ($branch->root_person_id === null) {
            throw new DomainException('Branch membership cannot be calculated before its founder is confirmed.');
        }

        return $this->traversal->getDescendantIds($branch->root_person_id, $depth);
    }

    private function branch(Branch|int $branch): Branch
    {
        return $branch instanceof Branch ? $branch : Branch::query()->findOrFail($branch);
    }
}
