<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;

class FamilyPolicy
{
    public function view(User $user, Family $family): bool
    {
        return $user->roleFor($family) !== null;
    }

    public function update(User $user, Family $family): bool
    {
        return $user->roleFor($family) === FamilyMembership::ROLE_ADMIN;
    }

    public function manageMembers(User $user, Family $family): bool
    {
        return $this->update($user, $family);
    }
}
