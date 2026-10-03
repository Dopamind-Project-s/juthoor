<?php

namespace App\Services\Genealogy;

use App\Models\Branch;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ProgressiveGraphService
{
    /** @return array<string, mixed> */
    public function build(FamilyTree $tree, int $rootId, int $depth, int $limit, bool $publicOnly, ?Branch $branch = null, string $mode = 'focus'): array
    {
        $allowed = fn (Builder $query): Builder => $publicOnly ? $query->publiclyVisible() : $query;
        $root = $allowed($tree->people()->getQuery())->whereKey($rootId)->firstOrFail();
        $nodes = collect([$root->id => $root]);
        $edges = collect();
        $frontier = [$root->id];
        $truncated = false;

        for ($level = 0; $level < $depth && $frontier !== []; $level++) {
            $relationships = $this->relationships($tree, $publicOnly)->whereIn('related_person_id', $frontier)->get();
            $candidateIds = $relationships->pluck('person_id')->unique()->values();
            $people = $allowed($tree->people()->getQuery())->whereKey($candidateIds)->get()->keyBy('id');
            $next = [];
            foreach ($relationships as $relationship) {
                if (! $people->has($relationship->person_id)) {
                    continue;
                }
                if ($nodes->count() >= $limit) {
                    $truncated = true;
                    break 2;
                }
                $nodes->put($relationship->person_id, $people[$relationship->person_id]);
                $edges->push($relationship);
                $next[] = $relationship->person_id;
            }
            $frontier = array_values(array_unique($next));
        }

        $states = [];
        if ($branch) {
            [$ancestorNodes, $ancestorEdges] = $this->ancestors($tree, $branch->root_person_id, $publicOnly, $limit - $nodes->count());
            foreach ($ancestorNodes as $person) {
                $nodes->put($person->id, $person);
                $states[$person->id] = 'ancestor_path';
            }
            $edges = $edges->merge($ancestorEdges)->unique('id')->values();
            $states[$branch->root_person_id] = 'branch_root';
            foreach ($nodes->keys() as $id) {
                $states[$id] ??= $mode === 'isolated' ? 'branch_descendant' : 'branch_descendant';
            }
            if ($mode === 'focus' && $nodes->count() < $limit) {
                $pathIds = $ancestorNodes->pluck('id');
                $sideEdges = $this->relationships($tree, $publicOnly)
                    ->whereIn('related_person_id', $pathIds)->whereNotIn('person_id', $nodes->keys())
                    ->limit($limit - $nodes->count())->get();
                $sidePeople = $allowed($tree->people()->getQuery())->whereKey($sideEdges->pluck('person_id'))->get();
                foreach ($sidePeople as $person) {
                    $nodes->put($person->id, $person);
                    $states[$person->id] = 'dimmed';
                }
                $edges = $edges->merge($sideEdges->whereIn('person_id', $sidePeople->modelKeys()))->unique('id')->values();
            }
        }

        $childCounts = $this->relationships($tree, $publicOnly)
            ->whereIn('related_person_id', $nodes->keys())
            ->selectRaw('related_person_id, count(*) as aggregate')
            ->groupBy('related_person_id')->pluck('aggregate', 'related_person_id');
        $loadedCounts = $edges->countBy('related_person_id');

        return [
            'tree' => ['id' => $tree->id, 'name' => $tree->name_ar],
            'root_id' => $rootId,
            'nodes' => $nodes->values()->map(fn (Person $person): array => [
                'id' => $person->id,
                'name' => $person->full_name_ar,
                'status' => $states[$person->id] ?? 'visible',
                'verification' => $person->verification_status,
                'has_more_children' => (int) ($childCounts[$person->id] ?? 0) > (int) ($loadedCounts[$person->id] ?? 0),
                'url' => route('genealogy.person', [$tree->family->slug, $person->id]),
            ])->all(),
            'edges' => $edges->map(fn (PersonRelationship $edge): array => [
                'id' => $edge->id, 'child_id' => $edge->person_id, 'parent_id' => $edge->related_person_id,
            ])->all(),
            'meta' => [
                'depth' => $depth, 'limit' => $limit, 'returned' => $nodes->count(),
                'truncated' => $truncated, 'complete' => ! $truncated && $frontier === [],
                'next_frontier' => ($truncated || $frontier !== []) ? $frontier : [],
            ],
        ];
    }

    private function relationships(FamilyTree $tree, bool $publicOnly): Builder|HasMany
    {
        $query = $tree->personRelationships()->where('relationship_type', PersonRelationship::TYPE_FATHER);

        return $publicOnly ? $query->verified() : $query;
    }

    /** @return array{Collection<int, Person>, Collection<int, PersonRelationship>} */
    private function ancestors(FamilyTree $tree, int $personId, bool $publicOnly, int $remaining): array
    {
        $availableEdges = $this->relationships($tree, $publicOnly)->get()->keyBy('person_id');
        $orderedIds = collect();
        $orderedEdges = collect();
        $current = $personId;
        while ($remaining-- > 0) {
            $orderedIds->prepend($current);
            $edge = $availableEdges->get($current);
            if (! $edge) {
                break;
            }
            $orderedEdges->prepend($edge);
            $current = $edge->related_person_id;
        }

        $people = $tree->people()->when($publicOnly, fn ($query) => $query->publiclyVisible())
            ->whereKey($orderedIds)->get()->keyBy('id');
        $nodes = $orderedIds->map(fn (int $id) => $people->get($id))->filter()->values();
        $allowedIds = $nodes->pluck('id');
        $edges = $orderedEdges->filter(fn (PersonRelationship $edge) => $allowedIds->contains($edge->person_id)
            && $allowedIds->contains($edge->related_person_id))->values();

        return [$nodes, $edges];
    }
}
