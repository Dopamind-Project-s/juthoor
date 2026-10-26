<?php

namespace App\Services\Community;

use App\Models\Branch;
use App\Models\CommunityEntity;
use Illuminate\Support\Collection;

/**
 * Entity statistics from actual records (v2.0 chapter 8). People are counted once by id across all anchors in scope,
 * so nested branches (a sub-fakhd inside its fakhd) never double-count. Three measures are kept apart: registered
 * (any recorded lineage), verified (verified lineage and verified record) and published (visible to visitors);
 * none of them is a population. Coverage against the real population is unknown and reported as such; the only
 * percentage is the share of registered people whose father link is verified, a data-quality measure.
 */
class CommunityStatistics
{
    public function __construct(private readonly CommunityGenealogy $genealogy) {}

    /**
     * @param  Collection<int, CommunityEntity>  $entities  the entity and, with descendants, its visible descendants
     * @return array<string, mixed>
     */
    public function compute(CommunityEntity $entity, Collection $entities, bool $public): array
    {
        $registered = $verified = $published = $roots = [];
        $trees = [];
        foreach ($this->genealogy->anchors($entities, $public) as $anchor) {
            $treeId = $anchor['tree']->id;
            $graph = $this->genealogy->graph($treeId);
            $trees[$treeId] = true;
            if ($anchor['branch']) {
                $rootId = (int) $anchor['branch']->root_person_id;
                $roots[$rootId] = true;
                $registered += $this->genealogy->members($treeId, $rootId, 'recorded');
                $verified += array_filter($this->genealogy->members($treeId, $rootId, 'verified'), fn ($v, int $id) => $graph['people'][$id]['verified'], ARRAY_FILTER_USE_BOTH);
                $published += $this->genealogy->members($treeId, $rootId, 'public');

                continue;
            }
            // A whole tree: everyone recorded in it; verified records reached through verified edges from the root.
            if ($anchor['tree']->root_person_id) {
                $roots[(int) $anchor['tree']->root_person_id] = true;
                $verified += array_filter($this->genealogy->members($treeId, (int) $anchor['tree']->root_person_id, 'verified'), fn ($v, int $id) => $graph['people'][$id]['verified'], ARRAY_FILTER_USE_BOTH);
            }
            $registered += array_fill_keys(array_keys($graph['people']), true);
            $published += $graph['public'];
        }

        $nonRoots = array_diff_key($registered, $roots);
        $withVerifiedFather = 0;
        $living = 0;
        foreach ($trees as $treeId => $true) {
            $graph = $this->genealogy->graph($treeId);
            foreach (array_intersect_key($nonRoots, $graph['people']) as $id => $true) {
                $withVerifiedFather += ($graph['fathers'][$id]['verified'] ?? false) ? 1 : 0;
            }
            foreach (array_intersect_key($registered, $graph['people']) as $id => $true) {
                $living += $graph['people'][$id]['living'] ? 1 : 0;
            }
        }
        $branchPeople = $public ? $published : $registered;
        $estimateSource = $entity->population_estimate !== null ? $entity->populationEstimateSource : null;

        return [
            'anchored' => $trees !== [],
            'registered' => count($registered),
            'verified' => count($verified),
            'published' => count($published),
            // Living people are never public; their number is shown to members only.
            'living' => $public ? null : $living,
            // Branches are few; filtering them in memory avoids binding thousands of person ids.
            'branches' => $trees === [] ? 0 : Branch::query()->whereIn('family_tree_id', array_keys($trees))
                ->when($public, fn ($query) => $query->published(), fn ($query) => $query->active())->get(['id', 'root_person_id'])
                ->filter(fn (Branch $branch) => isset($branchPeople[(int) $branch->root_person_id]))->count(),
            'fatherCoverage' => $nonRoots === [] ? null : (int) round(100 * $withVerifiedFather / count($nonRoots)),
            'estimate' => $estimateSource ? ['value' => $entity->population_estimate, 'year' => $entity->population_estimate_year, 'source' => $estimateSource] : null,
            'computedAt' => now(),
        ];
    }
}
