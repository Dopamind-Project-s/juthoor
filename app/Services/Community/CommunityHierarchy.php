<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use Illuminate\Support\Collection;

/**
 * Parent/child navigation of a family's community entities. A family holds tens to hundreds of entities, so one query
 * loads the whole parent map and the walks happen in memory; every walk stops at a repeated id.
 */
class CommunityHierarchy
{
    /** @return array<int, array{parent: int|null, status: string}> */
    public function nodes(int $familyId): array
    {
        return CommunityEntity::query()->where('family_id', $familyId)->toBase()->get(['id', 'parent_id', 'publication_status'])
            ->mapWithKeys(fn (object $row) => [(int) $row->id => ['parent' => $row->parent_id === null ? null : (int) $row->parent_id, 'status' => $row->publication_status]])
            ->all();
    }

    /**
     * Ancestor ids from the top of the hierarchy down to the direct parent.
     *
     * @param  array<int, array{parent: int|null, status: string}>|null  $nodes
     * @return array<int, int>
     */
    public function ancestorIds(CommunityEntity $entity, ?array $nodes = null): array
    {
        $nodes ??= $this->nodes($entity->family_id);
        $ancestors = [];
        $current = $entity->parent_id === null ? null : (int) $entity->parent_id;
        while ($current !== null && ! in_array($current, $ancestors, true) && $current !== $entity->id) {
            $ancestors[] = $current;
            $current = $nodes[$current]['parent'] ?? null;
        }

        return array_reverse($ancestors);
    }

    /**
     * @param  array<int, array{parent: int|null, status: string}>|null  $nodes
     * @return array<int, int>
     */
    public function descendantIds(CommunityEntity $entity, bool $includeSelf = true, ?array $nodes = null): array
    {
        $nodes ??= $this->nodes($entity->family_id);
        $children = [];
        foreach ($nodes as $id => $node) {
            if ($node['parent'] !== null) {
                $children[$node['parent']][] = $id;
            }
        }
        $found = [$entity->id => true];
        $queue = [$entity->id];
        while ($queue !== []) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                if (! isset($found[$child])) {
                    $found[$child] = true;
                    $queue[] = $child;
                }
            }
        }
        if (! $includeSelf) {
            unset($found[$entity->id]);
        }

        return array_keys($found);
    }

    /**
     * Entities a visitor may open: published, with every ancestor published. A published page under a draft or
     * archived parent stays hidden so the public breadcrumb never names an unpublished entity.
     *
     * @param  array<int, array{parent: int|null, status: string}>|null  $nodes
     * @return array<int, int>
     */
    public function publicIds(int $familyId, ?array $nodes = null): array
    {
        $nodes ??= $this->nodes($familyId);
        $public = [];
        foreach ($nodes as $id => $node) {
            $current = $id;
            $seen = [];
            while ($current !== null && ($nodes[$current]['status'] ?? null) === CommunityEntity::STATUS_PUBLISHED && ! isset($seen[$current])) {
                $seen[$current] = true;
                $current = $nodes[$current]['parent'];
            }
            if ($current === null) {
                $public[] = $id;
            }
        }

        return $public;
    }

    /**
     * Depth-first order for hierarchical lists; entities whose parent is not in the collection start a new root.
     *
     * @param  Collection<int, CommunityEntity>  $entities
     * @return array<int, array{entity: CommunityEntity, depth: int}>
     */
    public function ordered(Collection $entities): array
    {
        $byParent = $entities->sortBy([['sort_order', 'asc'], ['name_ar', 'asc']])
            ->groupBy(fn (CommunityEntity $entity) => $entity->parent_id !== null && $entities->contains('id', $entity->parent_id) ? $entity->parent_id : 0);
        $rows = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$rows, $byParent): void {
            foreach ($byParent->get($parent, collect()) as $entity) {
                if (! isset($rows[$entity->id])) {
                    $rows[$entity->id] = ['entity' => $entity, 'depth' => $depth];
                    $walk($entity->id, $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return array_values($rows);
    }
}
