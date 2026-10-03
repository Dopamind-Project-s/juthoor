<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\Person;
use App\Models\User;

class PersonPolicy
{
    public function view(User $user, Person $person): bool
    {
        return $user->roleFor($person->familyTree->family_id) !== null;
    }

    public function create(User $user, Family $family): bool
    {
        return $user->canEditFamily($family);
    }

    public function update(User $user, Person $person): bool
    {
        return $user->canEditFamily($person->familyTree->family_id);
    }

    public function delete(User $user, Person $person): bool
    {
        return $this->update($user, $person);
    }
}
