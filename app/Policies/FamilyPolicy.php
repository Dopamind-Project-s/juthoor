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

    public function manageSources(User $user, Family $family): bool
    {
        return $user->canEditFamily($family);
    }

    public function propose(User $user, Family $family): bool
    {
        return $this->view($user, $family);
    }

    public function review(User $user, Family $family): bool
    {
        return $this->update($user, $family);
    }

    /** Publishing genealogy (verified/sourced and visible) or changing a published record. */
    public function publish(User $user, Family $family): bool
    {
        return $this->update($user, $family);
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
