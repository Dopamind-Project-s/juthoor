<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\Family;
use App\Models\User;

class BranchPolicy
{
    public function create(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'branch.manage');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->hasFamilyPermission($branch->familyTree->family_id, 'branch.manage');
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->update($user, $branch);
    }
}
