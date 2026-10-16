<?php

namespace App\Services\Genealogy;

use App\Models\FamilyTree;
use App\Models\Person;

class PublishedTreeTraversal
{
    /** Profiles use the same visibility boundary as the complete tree, with no ancestor depth cap. */
    public function profile(FamilyTree $tree, Person $person, bool $private): array
    {
        $people = $tree->people()->when(! $private, fn ($q) => $q->treeVisible())->with('personAttributes')->get()->keyBy('id');
        $edges = $tree->personRelationships()->where('relationship_type', 'father')->where('is_primary', true)
            ->when(! $private, fn ($q) => $q->treeVisible())->get()
            ->filter(fn ($edge) => $people->has($edge->person_id) && $people->has($edge->related_person_id));
        $parents = $edges->keyBy('person_id');
        $children = $edges->groupBy('related_person_id');
        $path = collect();
        $seen = [];
        $id = $person->id;
        while ($id && $people->has($id) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $path->prepend($people[$id]);
            $id = $parents->get($id)?->related_person_id;
        }
        $descendants = collect();
        $queue = [[$person->id, 0]];
        $seen = [$person->id => true];
        for ($i = 0; $i < count($queue); $i++) {
            [$id, $depth] = $queue[$i];
            if ($depth === 2) {
                continue;
            }
            foreach ($children->get($id, collect()) as $edge) {
                if (isset($seen[$edge->person_id])) {
                    continue;
                }
                $seen[$edge->person_id] = true;
                $descendants->push($people[$edge->person_id]);
                $queue[] = [$edge->person_id, $depth + 1];
            }
        }

        return ['ancestorPath' => $path, 'descendants' => $descendants,
            'father' => $people->get($parents->get($person->id)?->related_person_id),
            'directChildren' => $children->get($person->id, collect())->map(fn ($edge) => $people[$edge->person_id])];
    }
}
