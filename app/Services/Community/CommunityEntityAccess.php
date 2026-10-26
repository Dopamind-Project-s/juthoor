<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\Family;
use App\Models\User;

/**
 * Entity-scoped roles. A manager of an entity manages it and its descendants, never its ancestors or siblings, and only
 * while still a member of the owning family. Family editors and administrators keep their family-wide scope.
 */
class CommunityEntityAccess
{
    public function __construct(private readonly CommunityHierarchy $hierarchy) {}

    public function roleOver(User $user, CommunityEntity $entity): ?string
    {
        if (! $user->is_active || $user->roleFor($entity->family_id) === null) {
            return null;
        }
        $roles = CommunityEntityManager::query()->where('user_id', $user->id)
            ->whereIn('community_entity_id', [...$this->hierarchy->ancestorIds($entity), $entity->id])->pluck('role');

        return match (true) {
            $roles->contains(CommunityEntityManager::ROLE_ADMIN) => CommunityEntityManager::ROLE_ADMIN,
            $roles->isNotEmpty() => CommunityEntityManager::ROLE_EDITOR,
            default => null,
        };
    }

    /**
     * Ids of the family's entities the user may edit, or null for the whole family.
     *
     * @return array<int, int>|null
     */
    public function manageableIds(User $user, Family $family): ?array
    {
        if ($user->canEditFamily($family)) {
            return null;
        }
        if (! $user->is_active || $user->roleFor($family) === null) {
            return [];
        }
        $nodes = $this->hierarchy->nodes($family->id);
        $ids = [];
        CommunityEntity::query()->where('family_id', $family->id)
            ->whereHas('managers', fn ($managers) => $managers->where('user_id', $user->id))->get()
            ->each(function (CommunityEntity $entity) use (&$ids, $nodes): void {
                $ids = [...$ids, ...$this->hierarchy->descendantIds($entity, true, $nodes)];
            });

        return array_values(array_unique($ids));
    }
}
