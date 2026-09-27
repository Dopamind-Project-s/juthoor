<?php

namespace App\Services\Genealogy;

use App\Models\Person;
use App\Models\PersonRelationship;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class GenealogyTraversalService
{
    public const DEFAULT_MAX_DEPTH = 100;

    /** @return Collection<int, Person> Root-to-person order. */
    public function getAncestorPath(int $personId, ?int $depth = null): Collection
    {
        $person = Person::query()->findOrFail($personId);
        $parents = $this->fatherMap($person->family_tree_id);
        $people = Person::query()->where('family_tree_id', $person->family_tree_id)->get()->keyBy('id');
        $limit = $this->depth($depth);
        $path = [];
        $visited = [];
        $currentId = $person->id;

        for ($level = 0; $level <= $limit; $level++) {
            if (isset($visited[$currentId])) {
                throw new RuntimeException('A cycle was detected while traversing ancestors.');
            }

            $visited[$currentId] = true;
            $path[] = $people->get($currentId);

            if (! isset($parents[$currentId])) {
                break;
            }

            $currentId = $parents[$currentId];
        }

        return new Collection(array_reverse(array_filter($path)));
    }

    /**
     * @return array{id: int, person: Person, children: array<int, mixed>}
     */
    public function getDescendantSubtree(int $personId, ?int $depth = null): array
    {
        $person = Person::query()->findOrFail($personId);
        $children = $this->childrenMap($person->family_tree_id);
        $people = Person::query()->where('family_tree_id', $person->family_tree_id)->get()->keyBy('id');
        $visited = [];

        return $this->buildSubtree($person->id, $children, $people, $this->depth($depth), $visited);
    }

    /** @return array<int, int> Includes the starting person. */
    public function getDescendantIds(int $personId, ?int $depth = null): array
    {
        $person = Person::query()->findOrFail($personId);

        return $this->walkDescendants(
            $person->id,
            $this->childrenMap($person->family_tree_id),
            $this->depth($depth),
        );
    }

    public function isDescendantOf(int $personId, int $ancestorId): bool
    {
        if ($personId === $ancestorId) {
            return false;
        }

        $people = Person::query()->whereKey([$personId, $ancestorId])->get()->keyBy('id');
        $person = $people->get($personId);
        $ancestor = $people->get($ancestorId);

        if (! $person || ! $ancestor || $person->family_tree_id !== $ancestor->family_tree_id) {
            return false;
        }

        return in_array($personId, $this->getDescendantIds($ancestorId), true);
    }

    /**
     * Return verified descendants branching away from a selected ancestor path.
     *
     * @param  array<int, int>  $path
     * @return array<int, int>
     */
    public function getSideBranches(array $path, ?int $depth = null): array
    {
        if ($path === []) {
            return [];
        }

        $person = Person::query()->findOrFail($path[0]);
        $children = $this->childrenMap($person->family_tree_id);
        $pathLookup = array_fill_keys($path, true);
        $sideIds = [];

        foreach ($path as $pathId) {
            foreach ($children[$pathId] ?? [] as $childId) {
                if (! isset($pathLookup[$childId])) {
                    $sideIds = [...$sideIds, ...$this->walkDescendants($childId, $children, $this->depth($depth))];
                }
            }
        }

        return array_values(array_unique($sideIds));
    }

    /** @return array<int, int> child ID => father ID */
    private function fatherMap(int $treeId): array
    {
        return PersonRelationship::query()->verified()
            ->where('family_tree_id', $treeId)
            ->where('relationship_type', PersonRelationship::TYPE_FATHER)
            ->pluck('related_person_id', 'person_id')
            ->mapWithKeys(fn ($fatherId, $childId): array => [(int) $childId => (int) $fatherId])
            ->all();
    }

    /** @return array<int, array<int, int>> father ID => child IDs */
    private function childrenMap(int $treeId): array
    {
        return PersonRelationship::query()->verified()
            ->where('family_tree_id', $treeId)
            ->where('relationship_type', PersonRelationship::TYPE_FATHER)
            ->get(['person_id', 'related_person_id'])
            ->groupBy('related_person_id')
            ->map(fn ($relationships): array => $relationships->pluck('person_id')->map(fn ($id): int => (int) $id)->all())
            ->all();
    }

    /**
     * @param  array<int, array<int, int>>  $children
     * @return array<int, int>
     */
    private function walkDescendants(int $rootId, array $children, int $maxDepth): array
    {
        $result = [];
        $visited = [];
        $queue = [[$rootId, 0]];

        while ($queue !== []) {
            [$currentId, $depth] = array_shift($queue);

            if (isset($visited[$currentId])) {
                throw new RuntimeException('A cycle was detected while traversing descendants.');
            }

            $visited[$currentId] = true;
            $result[] = $currentId;

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($children[$currentId] ?? [] as $childId) {
                $queue[] = [$childId, $depth + 1];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, int>>  $children
     * @param  Collection<int, Person>  $people
     * @param  array<int, bool>  $visited
     * @return array{id: int, person: Person, children: array<int, mixed>}
     */
    private function buildSubtree(int $personId, array $children, Collection $people, int $depth, array &$visited): array
    {
        if (isset($visited[$personId])) {
            throw new RuntimeException('A cycle was detected while building the descendant subtree.');
        }

        $visited[$personId] = true;
        $nodes = [];

        if ($depth > 0) {
            foreach ($children[$personId] ?? [] as $childId) {
                $nodes[] = $this->buildSubtree($childId, $children, $people, $depth - 1, $visited);
            }
        }

        unset($visited[$personId]);

        return ['id' => $personId, 'person' => $people->get($personId), 'children' => $nodes];
    }

    private function depth(?int $depth): int
    {
        return max(0, min($depth ?? self::DEFAULT_MAX_DEPTH, self::DEFAULT_MAX_DEPTH));
    }
}
