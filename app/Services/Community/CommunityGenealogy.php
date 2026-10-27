<?php

namespace App\Services\Community;

use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\CrossFamilyLink;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Services\Genealogy\CrossFamilyLinkService;
use Illuminate\Support\Collection;

/**
 * Genealogy seen from community entities, read from the existing paternal graph without copying it. Each tree's people
 * and primary father edges are loaded once per request; membership of a branch is the founder plus descendants
 * (as BranchMembershipService), computed for three audiences:
 * - recorded: any primary father edge (everything registered in Juthoor);
 * - verified: verified/sourced edges only (the official branch membership);
 * - public: publicly visible edges between publicly visible people (what visitors can see in the tree).
 * Visitor views only start from published branches and active trees.
 */
class CommunityGenealogy
{
    /** @var array<int, array<string, mixed>> */
    private array $graphs = [];

    public function __construct(private readonly CrossFamilyLinkService $links) {}

    /** @return array{people: array<int, array{verified: bool, living: bool}>, public: array<int, true>, children: array<string, array<int, array<int, int>>>, fathers: array<int, array{father: int, verified: bool}>} */
    public function graph(int $treeId): array
    {
        if (isset($this->graphs[$treeId])) {
            return $this->graphs[$treeId];
        }
        $people = [];
        foreach (Person::query()->where('family_tree_id', $treeId)->toBase()->get(['id', 'verification_status', 'is_living']) as $row) {
            $people[(int) $row->id] = ['verified' => in_array($row->verification_status, ['verified', 'sourced'], true), 'living' => (bool) $row->is_living];
        }
        $public = array_fill_keys(Person::query()->where('family_tree_id', $treeId)->publiclyVisible()->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $edgeQuery = fn () => PersonRelationship::query()->where('family_tree_id', $treeId)->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true);
        $publicEdges = array_fill_keys($edgeQuery()->publiclyVisible()->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $children = ['recorded' => [], 'verified' => [], 'public' => []];
        $fathers = [];
        foreach ($edgeQuery()->toBase()->get(['id', 'person_id', 'related_person_id', 'verification_status']) as $edge) {
            [$child, $father] = [(int) $edge->person_id, (int) $edge->related_person_id];
            if (! isset($people[$child], $people[$father])) {
                continue;
            }
            $verified = in_array($edge->verification_status, ['verified', 'sourced'], true);
            $children['recorded'][$father][] = $child;
            $fathers[$child] = ['father' => $father, 'verified' => $verified];
            if ($verified) {
                $children['verified'][$father][] = $child;
            }
            if (isset($publicEdges[(int) $edge->id], $public[$child], $public[$father])) {
                $children['public'][$father][] = $child;
            }
        }

        return $this->graphs[$treeId] = compact('people', 'public', 'children', 'fathers');
    }

    /**
     * Founder plus descendants along the given edge set, as ids.
     *
     * @return array<int, true>
     */
    public function members(int $treeId, int $rootId, string $edges): array
    {
        $graph = $this->graph($treeId);
        if (! isset($graph['people'][$rootId]) || ($edges === 'public' && ! isset($graph['public'][$rootId]))) {
            return [];
        }
        $found = [$rootId => true];
        $queue = [$rootId];
        while ($queue !== []) {
            foreach ($graph['children'][$edges][array_pop($queue)] ?? [] as $child) {
                if (! isset($found[$child])) {
                    $found[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }

    /**
     * The genealogical anchors of the entities in scope: a linked branch (founder subtree) or, without a branch,
     * a linked tree as a whole. Visitors only get published branches and active trees.
     *
     * @param  Collection<int, CommunityEntity>  $entities
     * @return array<int, array{entity: CommunityEntity, tree: FamilyTree, branch: ?Branch}>
     */
    public function anchors(Collection $entities, bool $public): array
    {
        $branches = Branch::query()->whereIn('id', $entities->pluck('branch_id')->filter())
            ->when($public, fn ($query) => $query->published(), fn ($query) => $query->whereNotNull('root_person_id'))
            ->with('familyTree')->get()->keyBy('id');
        $trees = FamilyTree::query()->whereIn('id', $entities->pluck('family_tree_id')->filter())
            ->when($public, fn ($query) => $query->active())->get()->keyBy('id');
        $anchors = [];
        foreach ($entities as $entity) {
            if ($entity->branch_id && ($branch = $branches->get($entity->branch_id)) && $branch->familyTree && (! $public || $branch->familyTree->is_active)) {
                $anchors[] = ['entity' => $entity, 'tree' => $branch->familyTree, 'branch' => $branch];
            } elseif (! $entity->branch_id && $entity->family_tree_id && ($tree = $trees->get($entity->family_tree_id))) {
                $anchors[] = ['entity' => $entity, 'tree' => $tree, 'branch' => null];
            }
        }

        return $anchors;
    }

    /**
     * Founder card, ancestor chain, a two-generation mini tree, related branches and approved shared ancestors for one
     * entity; null when the entity has no visible genealogical anchor (community-only entities are valid).
     *
     * @return array<string, mixed>|null
     */
    public function preview(CommunityEntity $entity, bool $public): ?array
    {
        $anchor = $this->anchors(collect([$entity]), $public)[0] ?? null;
        if (! $anchor) {
            return null;
        }
        $tree = $anchor['tree'];
        $graph = $this->graph($tree->id);
        $edges = $public ? 'public' : 'recorded';
        $rootId = $anchor['branch']?->root_person_id ?? $tree->root_person_id;
        $visible = $rootId && isset($graph['people'][$rootId]) && (! $public || isset($graph['public'][$rootId]));
        if (! $visible) {
            return ['tree' => $tree, 'branch' => $anchor['branch'], 'founder' => null, 'ancestors' => collect(), 'children' => collect(), 'grandchildren' => [], 'relatedBranches' => collect(), 'sharedAncestors' => collect(), 'descendantCount' => 0];
        }

        $chain = [];
        for ($id = $rootId, $guard = 0; isset($graph['fathers'][$id]) && $guard < 200; $guard++) {
            $father = $graph['fathers'][$id]['father'];
            // Follow the father only through an edge visible in this mode, and never around a loop.
            if (! in_array($id, $graph['children'][$edges][$father] ?? [], true) || in_array($father, $chain, true)) {
                break;
            }
            array_unshift($chain, $father);
            $id = $father;
        }
        $childIds = $graph['children'][$edges][$rootId] ?? [];
        $grandchildren = [];
        foreach ($childIds as $child) {
            $grandchildren[$child] = count($graph['children'][$edges][$child] ?? []);
        }
        $shown = array_slice($childIds, 0, 24);
        $persons = Person::query()->whereIn('id', [$rootId, ...$chain, ...$shown])->get(['id', 'full_name_ar', 'first_name_ar', 'family_tree_id', 'verification_status'])->keyBy('id');
        $members = $this->members($tree->id, $rootId, $edges);

        return [
            'tree' => $tree,
            'branch' => $anchor['branch'],
            'founder' => $persons->get($rootId),
            'ancestors' => collect($chain)->map(fn (int $id) => $persons->get($id))->filter()->values(),
            'children' => collect($shown)->map(fn (int $id) => $persons->get($id))->filter()->values(),
            'hiddenChildren' => max(0, count($childIds) - count($shown)),
            'grandchildren' => $grandchildren,
            'descendantCount' => count($members) - 1,
            'relatedBranches' => Branch::query()->where('family_tree_id', $tree->id)
                ->when($anchor['branch'], fn ($query) => $query->whereKeyNot($anchor['branch']->id))
                ->when($public, fn ($query) => $query->published(), fn ($query) => $query->whereNotNull('root_person_id'))
                ->orderBy('name_ar')->get(['id', 'name_ar', 'slug', 'branch_type', 'family_tree_id', 'root_person_id'])
                ->filter(fn (Branch $branch) => isset($members[(int) $branch->root_person_id]))->take(30)->values(),
            'sharedAncestors' => $this->sharedAncestors([$rootId, ...$chain], $public),
        ];
    }

    /**
     * Approved cross-family links on the founder or an ancestor; for visitors only links whose validity still holds.
     *
     * @param  array<int, int>  $personIds
     * @return Collection<int, array{person_id: int, family: string}>
     */
    private function sharedAncestors(array $personIds, bool $public): Collection
    {
        $links = CrossFamilyLink::query()->where('status', 'approved')
            ->where(fn ($query) => $query->whereIn('left_person_id', $personIds)->orWhereIn('right_person_id', $personIds))
            ->with('leftFamily:id,name_ar,is_active', 'rightFamily:id,name_ar,is_active')->get();
        $links = $public ? $this->links->verified($links) : $links;

        return $links->map(function (CrossFamilyLink $link) use ($personIds): array {
            $left = in_array((int) $link->left_person_id, $personIds, true);

            return ['person_id' => (int) ($left ? $link->left_person_id : $link->right_person_id), 'family' => ($left ? $link->rightFamily : $link->leftFamily)?->name_ar ?? ''];
        })->filter(fn (array $row) => $row['family'] !== '')->values();
    }
}
