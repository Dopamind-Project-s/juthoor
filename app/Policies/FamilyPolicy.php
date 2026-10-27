<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\User;

/**
 * Family abilities map onto catalog permissions (App\Services\Access\PermissionCatalog): membership roles keep their rights
 * and functional roles add narrowly scoped ones. Super admins pass through Gate::before.
 */
class FamilyPolicy
{
    public function view(User $user, Family $family): bool
    {
        return $user->roleFor($family) !== null;
    }

    public function manageSources(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'source.manage');
    }

    public function propose(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'person.propose');
    }

    public function review(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'genealogy.review');
    }

    /** Publishing genealogy (verified/sourced and visible) or changing a published record. */
    public function publish(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'genealogy.publish');
    }

    public function managePrivacy(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'privacy.manage');
    }

    public function merge(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'person.merge');
    }

    public function recover(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'audit.recover');
    }

    public function update(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'family.settings');
    }

    public function manageMembers(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'family.members.manage');
    }

    public function assignRoles(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'role.assign');
    }

    public function moderateDirectory(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'directory.moderate');
    }

    public function reviewDeathNotices(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'news.death-notices');
    }
}
