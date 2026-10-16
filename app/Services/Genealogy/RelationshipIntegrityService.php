<?php

namespace App\Services\Genealogy;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use DomainException;

class RelationshipIntegrityService
{
    public function validate(PersonRelationship $relationship): void
    {
        if ((int) $relationship->person_id === (int) $relationship->related_person_id) {
            throw new DomainException('A person cannot be related to themselves.');
        }

        $people = Person::query()
            ->whereKey([$relationship->person_id, $relationship->related_person_id])
            ->get()->keyBy('id');
        $person = $people->get($relationship->person_id);
        $related = $people->get($relationship->related_person_id);

        if (! $person || ! $related) {
            throw new DomainException('Both people must exist before a relationship is stored.');
        }

        if ($person->family_tree_id !== $related->family_tree_id
            || (int) $relationship->family_tree_id !== $person->family_tree_id) {
            throw new DomainException('Both people and the relationship must belong to the same family tree.');
        }

        $duplicate = PersonRelationship::query()
            ->where('person_id', $relationship->person_id)
            ->where('related_person_id', $relationship->related_person_id)
            ->where('relationship_type', $relationship->relationship_type)
            ->when($relationship->exists, fn ($query) => $query->whereKeyNot($relationship->getKey()))
            ->exists();

        if ($duplicate) {
            throw new DomainException('An identical relationship already exists.');
        }

        if ($relationship->relationship_type === PersonRelationship::TYPE_FATHER
            && $relationship->is_primary
            && $this->hasAnotherPrimaryFather($relationship)) {
            throw new DomainException('A person cannot have more than one primary father.');
        }

        if ($relationship->relationship_type === PersonRelationship::TYPE_FATHER
            && FamilyTree::query()
                ->whereKey($relationship->family_tree_id)
                ->where('root_person_id', $relationship->person_id)
                ->exists()) {
            throw new DomainException('A confirmed family tree root cannot have a verified primary father.');
        }

        if ($relationship->relationship_type === PersonRelationship::TYPE_FATHER
            && $this->wouldCreateCycle($relationship)) {
            throw new DomainException('The father relationship would create a genealogy cycle.');
        }
    }

    private function hasAnotherPrimaryFather(PersonRelationship $relationship): bool
    {
        return PersonRelationship::query()
            ->where('family_tree_id', $relationship->family_tree_id)
            ->where('person_id', $relationship->person_id)
            ->where('relationship_type', PersonRelationship::TYPE_FATHER)
            ->where('is_primary', true)
            ->when($relationship->exists, fn ($query) => $query->whereKeyNot($relationship->getKey()))
            ->exists();
    }

    private function wouldCreateCycle(PersonRelationship $relationship): bool
    {
        $parents = PersonRelationship::query()
            ->where('family_tree_id', $relationship->family_tree_id)
            ->where('relationship_type', PersonRelationship::TYPE_FATHER)
            ->when($relationship->exists, fn ($query) => $query->whereKeyNot($relationship->getKey()))
            ->pluck('related_person_id', 'person_id')
            ->all();
        $currentId = (int) $relationship->related_person_id;
        $childId = (int) $relationship->person_id;
        $visited = [];

        while (isset($parents[$currentId])) {
            if (isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;
            $currentId = (int) $parents[$currentId];

            if ($currentId === $childId) {
                return true;
            }
        }

        return false;
    }
}
