<?php

namespace App\Services\Genealogy;

use App\Models\Branch;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Services\ArabicNameNormalizer;
use Illuminate\Support\Collection;

/**
 * Read-only context for placing a person in the tree: the chosen father with his lineage and derived branches, possible
 * duplicates before saving, and the impact of changing an existing person's father. Branch membership is always derived
 * from the approved father chain (branch founders among the ancestors), never from labels chosen in a form.
 * Nothing here writes, merges or decides; it only informs the person who does.
 */
class PersonPlacementService
{
    public function __construct(
        private readonly GenealogyTraversalService $traversal,
        private readonly DuplicatePersonDetector $duplicates,
        private readonly ArabicNameNormalizer $normalizer,
    ) {}

    /**
     * @return array{id: int, name: string, lineage: string, branches: array<int, string>, children: array<int, string>, verification: string}
     */
    public function fatherContext(FamilyTree $tree, Person $father): array
    {
        $ancestors = $this->traversal->getAncestorPath($father->id, 12, false);

        return [
            'id' => $father->id,
            'name' => $father->display_name,
            'lineage' => $this->lineageText($ancestors, $father),
            'branches' => $this->derivedBranches($tree, $ancestors->modelKeys())->pluck('name_ar')->all(),
            'children' => $tree->people()->whereHas('outgoingRelationships', fn ($edge) => $this->primaryFather($edge)->where('related_person_id', $father->id))
                ->orderBy('full_name_ar')->limit(30)->pluck('full_name_ar')->all(),
            'verification' => $father->verification_status,
        ];
    }

    /**
     * Possible duplicates for a new (or renamed) person. "blocking" matches need explicit confirmation on save
     * (DuplicatePersonDetector); the others are shown so the user can pick the existing person instead.
     *
     * @return array<int, array{id: int, name: string, lineage: string, reason: string, blocking: bool, verification: string}>
     */
    public function candidates(FamilyTree $tree, string $fullName, ?int $fatherId, ?int $excludeId = null): array
    {
        $normalized = $this->normalizer->normalize($fullName);
        if (mb_strlen($normalized) < 2) {
            return [];
        }
        $found = [];
        foreach ($this->duplicates->matches($tree, $fullName, $fatherId, 8) as $match) {
            $found[$match->id] = ['reason' => $fatherId ? 'الاسم نفسه وللأب نفسه' : 'الاسم نفسه ودون أب مسجل', 'blocking' => true];
        }
        if ($fatherId) {
            // Brothers with a similar spelling or the same first name: often one person entered twice.
            $first = explode(' ', $normalized)[0];
            $tree->people()->whereHas('outgoingRelationships', fn ($edge) => $this->primaryFather($edge)->where('related_person_id', $fatherId))
                ->where(fn ($query) => $query->where('name_search', 'like', $first.'%')->orWhere('name_search', 'like', '%'.$normalized.'%'))
                ->limit(8)->pluck('id')->each(function (int $id) use (&$found): void {
                    $found[$id] ??= ['reason' => 'أخ بالاسم الأول نفسه أو اسم مشابه', 'blocking' => false];
                });
        }
        $tree->people()->sameName($fullName)->limit(8)->pluck('id')->each(function (int $id) use (&$found): void {
            $found[$id] ??= ['reason' => 'الاسم نفسه في موضع آخر من الشجرة', 'blocking' => false];
        });
        unset($found[$excludeId]);

        return $tree->people()->whereKey(array_keys($found))->orderBy('id')->get()->map(fn (Person $person) => [
            'id' => $person->id,
            'name' => $person->display_name,
            'lineage' => $this->lineageText($this->traversal->getAncestorPath($person->id, 6, false), $person),
            'reason' => $found[$person->id]['reason'],
            'blocking' => $found[$person->id]['blocking'],
            'verification' => $person->verification_status,
        ])->sortByDesc('blocking')->values()->all();
    }

    /**
     * What depends on this person: descendants that would move with a father change, branches they found or belong to,
     * and other families sharing them as an ancestor.
     *
     * @return array{descendants: int, founded: array<int, string>, member_of: array<int, string>, shared_families: array<int, string>, father_id: int|null}
     */
    public function impact(FamilyTree $tree, Person $person): array
    {
        $ancestors = $this->traversal->getAncestorPath($person->id, null, false);

        return [
            'descendants' => count($this->traversal->getDescendantIds($person->id, null, false)) - 1,
            'founded' => $person->foundedBranches()->where('family_tree_id', $tree->id)->pluck('name_ar')->all(),
            'member_of' => $this->derivedBranches($tree, $ancestors->modelKeys())->pluck('name_ar')->all(),
            'shared_families' => app(SharedAncestryScope::class)->impactedFamilies($person)->pluck('name_ar')->all(),
            'father_id' => $person->outgoingRelationships()->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true)->value('related_person_id'),
        ];
    }

    /** "بن الأب بن الجد" from the nearest ancestor upward. */
    public function lineageText(Collection $ancestors, Person $person): string
    {
        return $ancestors->reject(fn (Person $ancestor) => $ancestor->is($person))->reverse()->take(5)
            ->map(fn (Person $ancestor) => 'بن '.$ancestor->full_name_ar)->implode(' ');
    }

    /** @param array<int, int> $ancestorIds */
    private function derivedBranches(FamilyTree $tree, array $ancestorIds): Collection
    {
        return $tree->branches()->whereIn('root_person_id', $ancestorIds)->orderBy('id')->get(['id', 'name_ar', 'root_person_id'])
            ->sortBy(fn (Branch $branch) => array_search($branch->root_person_id, $ancestorIds, true))->values();
    }

    private function primaryFather($edge)
    {
        return $edge->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true);
    }
}
