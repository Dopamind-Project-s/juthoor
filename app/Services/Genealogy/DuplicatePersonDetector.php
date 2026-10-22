<?php

namespace App\Services\Genealogy;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * UC-03 / chapter 15: a new person whose Arabic-normalized name (or recorded variant) matches someone with the same
 * primary father — or, when no father is given, someone without a primary father — needs explicit confirmation.
 * Matching only warns; records are never merged automatically.
 */
class DuplicatePersonDetector
{
    /** @return Collection<int, Person> */
    public function matches(FamilyTree $tree, string $fullName, ?int $fatherId, int $limit = 5): Collection
    {
        $primaryFather = fn ($edge) => $edge->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true);

        return $tree->people()
            ->sameName($fullName)
            ->when($fatherId,
                fn ($query) => $query->whereHas('outgoingRelationships', fn ($edge) => $primaryFather($edge)->where('related_person_id', $fatherId)),
                fn ($query) => $query->whereDoesntHave('outgoingRelationships', $primaryFather))
            ->orderBy('id')->limit($limit)->get(['id', 'full_name_ar', 'canonical_key']);
    }

    public function assertDistinct(FamilyTree $tree, string $fullName, ?int $fatherId): void
    {
        $matches = $this->matches($tree, $fullName, $fatherId);
        if ($matches->isNotEmpty()) {
            throw ValidationException::withMessages(['confirm_distinct' => 'يوجد في هذه الشجرة شخص بالاسم نفسه '.($fatherId ? 'وللأب نفسه' : 'ودون أب مسجل').': '
                .$this->describe($matches).'. افتح السجل القائم بدل إنشاء نسخة، أو أكّد أنه شخص مختلف.']);
        }
    }

    /** @param  Collection<int, Person>  $matches */
    public function describe(Collection $matches): string
    {
        return $matches->map(fn (Person $match) => $match->full_name_ar.' (#'.$match->id.($match->canonical_key ? ' · '.$match->canonical_key : '').')')->implode('، ');
    }
}
