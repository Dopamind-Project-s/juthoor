<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\Family;
use App\Models\User;

class BranchPolicy
{
    public function create(User $user, Family $family): bool
    {
        return $user->canEditFamily($family);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->canEditFamily($branch->familyTree->family_id);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->update($user, $branch);
    }
}
