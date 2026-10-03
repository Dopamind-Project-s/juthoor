<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\PersonRelationship;
use App\Models\User;

class PersonRelationshipPolicy
{
    public function create(User $user, Family $family): bool
    {
        return $user->canEditFamily($family);
    }

    public function delete(User $user, PersonRelationship $relationship): bool
    {
        return $user->canEditFamily($relationship->familyTree->family_id);
    }
}
