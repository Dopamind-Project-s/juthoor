<?php

namespace App\Services\Community;

use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\FamilyTree;
use DomainException;

/** Structural rules checked on every save, whatever the caller (cf. BranchIntegrityService for genealogical branches). */
class CommunityEntityIntegrityService
{
    public function __construct(private readonly CommunityHierarchy $hierarchy) {}

    public function validate(CommunityEntity $entity): void
    {
        if (! isset(CommunityEntity::RANKS[$entity->entity_type])) {
            throw new DomainException('Unknown community entity type.');
        }
        if (! isset(CommunityEntity::STATUSES[$entity->publication_status])) {
            throw new DomainException('Unknown publication status.');
        }
        if ($entity->exists && $entity->isDirty('family_id')) {
            throw new DomainException('A community entity cannot move to another family.');
        }

        $this->validateGenealogyLinks($entity);
        $this->validateParent($entity);

        if ($entity->exists && $entity->isDirty('entity_type')) {
            $rank = CommunityEntity::RANKS[$entity->entity_type];
            $higherChild = $entity->children()->get(['entity_type'])
                ->contains(fn (CommunityEntity $child) => (CommunityEntity::RANKS[$child->entity_type] ?? PHP_INT_MAX) < $rank);
            if ($higherChild) {
                throw new DomainException('A community entity cannot be placed below its own children.');
            }
        }
    }

    private function validateGenealogyLinks(CommunityEntity $entity): void
    {
        if ($entity->family_tree_id !== null
            && ! FamilyTree::query()->whereKey($entity->family_tree_id)->where('family_id', $entity->family_id)->exists()) {
            throw new DomainException('A related tree must belong to the entity family.');
        }
        if ($entity->branch_id === null) {
            return;
        }

        $branch = Branch::query()->with('familyTree:id,family_id')->find($entity->branch_id);
        if (! $branch || (int) $branch->familyTree?->family_id !== (int) $entity->family_id) {
            throw new DomainException('A related branch must belong to the entity family.');
        }
        if ($entity->family_tree_id !== null && (int) $branch->family_tree_id !== (int) $entity->family_tree_id) {
            throw new DomainException('A related branch must belong to the related tree.');
        }
    }

    private function validateParent(CommunityEntity $entity): void
    {
        if ($entity->parent_id === null) {
            return;
        }
        if ($entity->exists && (int) $entity->parent_id === (int) $entity->id) {
            throw new DomainException('A community entity cannot be its own parent.');
        }

        $parent = CommunityEntity::query()->find($entity->parent_id);
        if (! $parent || (int) $parent->family_id !== (int) $entity->family_id) {
            throw new DomainException('A parent entity must belong to the same family.');
        }
        if ($entity->exists && in_array((int) $entity->id, [...$this->hierarchy->ancestorIds($parent), $parent->id], true)) {
            throw new DomainException('The parent entity would create a hierarchy cycle.');
        }
        if (CommunityEntity::RANKS[$entity->entity_type] < (CommunityEntity::RANKS[$parent->entity_type] ?? 0)) {
            throw new DomainException('A community entity cannot be placed above its parent level.');
        }
    }
}
