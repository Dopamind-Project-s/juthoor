<?php

namespace App\Services\Genealogy;

use App\Models\Family;
use App\Models\FamilyTree;

class SelectedFamilyTree
{
    public function resolve(Family $family, bool $public = false): FamilyTree
    {
        $slug = request()->query('tree');
        abort_if($slug !== null && ! is_string($slug), 422);
        $query = $family->familyTrees()->when($public, fn ($query) => $query->active());

        return $slug !== null ? $query->where('slug', $slug)->firstOrFail() : $query->primary()->firstOrFail();
    }
}
