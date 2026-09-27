<?php

namespace App\Services\Genealogy;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use DomainException;

class FamilyTreeIntegrityService
{
    public function validate(FamilyTree $tree): void
    {
        if ($tree->root_person_id === null) {
            return;
        }

        $root = Person::query()->find($tree->root_person_id);

        if (! $root || $root->family_tree_id !== $tree->getKey()) {
            throw new DomainException('A tree root must belong to that family tree.');
        }

        if ($root->outgoingRelationships()
            ->verified()
            ->where('relationship_type', PersonRelationship::TYPE_FATHER)
            ->exists()) {
            throw new DomainException('A confirmed family tree root cannot have a verified primary father.');
        }
    }
}
