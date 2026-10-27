<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use App\Models\MediaItem;
use App\Models\User;

/**
 * Who may read a media file. Viewers get the display copy of a published file: public files when the owner entity's
 * page is public, member files for members of the family; published restricted files only reach publishers of the owner entity.
 * Drafts, submissions, archived files and originals are for the owner entity's managers only.
 */
class MediaAccess
{
    /** @var array<int, array<int, true>> */
    private array $publicEntities = [];

    /** Per-instance answers keyed by user, ability and entity or family, so a page with many files asks once each. @var array<string, bool> */
    private array $decisions = [];

    /** @var array<string, array<int, int>|null> */
    private array $scopes = [];

    public function __construct(
        private readonly CommunityHierarchy $hierarchy,
        private readonly CommunityEntityAccess $access,
    ) {}

    public function canView(?User $user, MediaItem $media, string $variant = 'display'): bool
    {
        $owner = $media->ownerEntity;
        if (! $owner) {
            return false;
        }
        $manages = $this->allows($user, 'update', $owner);
        // Restricted material is for publishers only, in every variant.
        if ($media->audience === 'restricted' && $media->publication_status === MediaItem::STATUS_PUBLISHED) {
            return $this->allows($user, 'publish', $owner);
        }
        if ($variant === 'original' || $media->publication_status !== MediaItem::STATUS_PUBLISHED) {
            return (bool) $manages;
        }

        return match ($media->audience) {
            'public' => $manages || isset($this->publicEntities($owner->family_id)[$owner->id]),
            'members' => $manages || ($user?->is_active && ($this->decisions["{$user->id}:member:{$media->family_id}"] ??= ($user->isSuperAdmin() || $user->roleFor($media->family_id) !== null))),
            default => $this->allows($user, 'publish', $owner),
        };
    }

    /**
     * Same answers as CommunityEntityPolicy::update/publish, from one scope computation per user and family
     * (an inactive family grants only super admins, as Gate::before does).
     */
    private function allows(?User $user, string $ability, CommunityEntity $entity): bool
    {
        if (! $user?->is_active) {
            return false;
        }
        $key = "{$user->id}:{$ability}:family:{$entity->family_id}";
        if (! array_key_exists($key, $this->scopes)) {
            $family = $entity->family;
            $this->scopes[$key] = ! $family->is_active && ! $user->isSuperAdmin() ? []
                : ($ability === 'publish' ? $this->access->publishableIds($user, $family) : $this->access->manageableIds($user, $family, false));
        }

        return $this->scopes[$key] === null || in_array($entity->id, $this->scopes[$key], true);
    }

    /** @return array<int, true> */
    private function publicEntities(int $familyId): array
    {
        return $this->publicEntities[$familyId] ??= array_fill_keys($this->hierarchy->publicIds($familyId), true);
    }
}
