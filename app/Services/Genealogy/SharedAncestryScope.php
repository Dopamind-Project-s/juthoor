<?php

namespace App\Services\Genealogy;

use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonRelationship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A person is a shared ancestor when an approved cross-family link endpoint descends from them (or is them).
 * Facts about such a person affect every linked family, so only a platform reviewer (super admin) may change them.
 */
class SharedAncestryScope
{
    public function __construct(private readonly GenealogyTraversalService $traversal) {}

    /** @return Collection<int, Family> families affected by a change to this record (empty when not shared) */
    public function impactedFamilies(Model $record): Collection
    {
        $personIds = match (true) {
            $record instanceof Person => [$record->id],
            $record instanceof PersonRelationship => array_filter(array_unique([$record->person_id, $record->getOriginal('person_id')])),
            default => [],
        };
        $personIds = array_values(array_filter($personIds));
        if ($personIds === []) {
            return collect();
        }
        $people = Person::query()->with('familyTree')->whereKey($personIds)->get();
        $families = collect();
        foreach ($people as $person) {
            $familyId = $person->familyTree->family_id;
            $links = CrossFamilyLink::query()->where('status', 'approved')
                ->where(fn ($query) => $query->where('left_family_id', $familyId)->orWhere('right_family_id', $familyId))
                ->get();
            foreach ($links as $link) {
                $endpoint = $link->left_family_id === $familyId ? $link->left_person_id : $link->right_person_id;
                $endpointPerson = $endpoint ? Person::query()->find($endpoint) : null;
                if (! $endpointPerson || $endpointPerson->family_tree_id !== $person->family_tree_id) {
                    continue;
                }
                if ($endpoint === $person->id || $this->traversal->getAncestorPath($endpoint, null, false)->contains('id', $person->id)) {
                    $families->push($link->left_family_id, $link->right_family_id);
                }
            }
        }

        return Family::query()->whereKey($families->unique()->all())->orderBy('name_ar')->get();
    }
}
