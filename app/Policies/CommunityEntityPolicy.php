<?php

namespace App\Policies;

use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Services\Access\PermissionCatalog;
use App\Services\Community\CommunityEntityAccess;

/**
 * Family editors and administrators manage every entity of their family; entity managers manage their subtree. Only family
 * administrators and entity administrators publish, archive or change a published page. Super admins pass through Gate::before.
 */
class CommunityEntityPolicy
{
    public function __construct(private readonly CommunityEntityAccess $access) {}

    public function viewAny(User $user, Family $family): bool
    {
        return $this->access->manageableIds($user, $family) !== [];
    }

    /** A top-level entity is a family-wide decision; a child entity needs authority over its parent. */
    public function create(User $user, Family $family, ?CommunityEntity $parent = null): bool
    {
        return $parent ? $this->update($user, $parent) : $user->canEditFamily($family);
    }

    /** $module narrows a functional content grant: news permissions cover only PermissionCatalog::NEWS_MODULES. */
    public function update(User $user, CommunityEntity $entity, ?string $module = null): bool
    {
        return (bool) $entity->family?->is_active
            && ($user->canEditFamily($entity->family_id) || $this->access->roleOver($user, $entity) !== null
                || $this->granted($user, $entity, $module, 'content.edit', 'news.edit'));
    }

    public function publish(User $user, CommunityEntity $entity, ?string $module = null): bool
    {
        return (bool) $entity->family?->is_active && $user->is_active
            && ($user->roleFor($entity->family_id) === FamilyMembership::ROLE_ADMIN || $this->access->roleOver($user, $entity) === CommunityEntityManager::ROLE_ADMIN
                || $this->granted($user, $entity, $module, 'content.publish', 'news.publish'));
    }

    private function granted(User $user, CommunityEntity $entity, ?string $module, string $contentPermission, string $newsPermission): bool
    {
        return $user->hasFamilyPermission($entity->family_id, $contentPermission)
            || (in_array($module, PermissionCatalog::NEWS_MODULES, true) && $user->hasFamilyPermission($entity->family_id, $newsPermission));
    }

    public function manageManagers(User $user, CommunityEntity $entity): bool
    {
        return $user->can('manageMembers', $entity->family);
    }
}
