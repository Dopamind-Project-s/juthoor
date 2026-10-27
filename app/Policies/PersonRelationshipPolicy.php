<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\PersonRelationship;
use App\Models\User;

class PersonRelationshipPolicy
{
    public function create(User $user, Family $family): bool
    {
        return $user->hasFamilyPermission($family, 'person.edit-unpublished');
    }

    public function update(User $user, PersonRelationship $relationship): bool
    {
        return $user->hasFamilyPermission($relationship->familyTree->family_id, 'person.edit-unpublished');
    }

    public function delete(User $user, PersonRelationship $relationship): bool
    {
        return $user->hasFamilyPermission($relationship->familyTree->family_id, 'person.edit-unpublished');
    }
}
