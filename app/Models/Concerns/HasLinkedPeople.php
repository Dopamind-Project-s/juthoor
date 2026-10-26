<?php

namespace App\Models\Concerns;

use App\Models\Person;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * References existing genealogy people without changing them; pages show a person only to viewers allowed to see that
 * person (publicly visible people for visitors).
 */
trait HasLinkedPeople
{
    public function linkedPeople(): MorphToMany
    {
        return $this->morphToMany(Person::class, 'linkable', 'content_person_links')->withTimestamps();
    }
}
