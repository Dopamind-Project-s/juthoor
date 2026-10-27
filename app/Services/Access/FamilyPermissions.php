<?php

namespace App\Services\Access;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Permission;
use App\Models\User;

/**
 * Effective family permissions: the membership role's catalog rights plus the rights of functional roles assigned through
 * that same membership. Nothing is inherited from another family, and an inactive account has no rights.
 */
class FamilyPermissions
{
    /** @return array<int, string> */
    public function for(User $user, Family|int $family): array
    {
        // Strict: a freshly created model may not carry the column default yet (services mirror the gate without a refresh).
        if ($user->is_active === false) {
            return [];
        }
        if ($user->isSuperAdmin()) {
            return array_keys(PermissionCatalog::PERMISSIONS);
        }
        $membership = FamilyMembership::query()->where('family_id', $family instanceof Family ? $family->id : $family)
            ->where('user_id', $user->id)->first();
        if (! $membership) {
            return [];
        }
        $assigned = Permission::query()->where('scope', PermissionCatalog::SCOPE_FAMILY)->whereNull('deprecated_at')
            ->whereHas('roles', fn ($roles) => $roles->whereNull('deprecated_at')
                ->whereHas('assignments', fn ($assignments) => $assignments->where('family_membership_id', $membership->id)))
            ->pluck('key')->all();

        return array_values(array_unique([...(PermissionCatalog::MEMBERSHIP_PERMISSIONS[$membership->role] ?? []), ...$assigned]));
    }

    public function has(User $user, Family|int $family, string $permission): bool
    {
        return in_array($permission, $this->for($user, $family), true);
    }
}
