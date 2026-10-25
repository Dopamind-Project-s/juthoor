<?php

namespace App\Services\Genealogy;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Dashboards\FamilyHealthService;

/**
 * Possible duplicates for human review (UC-06), built on the existing duplicate rule: same normalized name — or a
 * recorded alternate spelling — under the same primary father (or both without one). Never merges anything.
 */
class MergeCandidateFinder
{
    public function __construct(private readonly FamilyHealthService $health, private readonly PersonPrivacy $privacy) {}

    /** @return array<int, array{a: Person, b: Person, reason: string, signals: array<int, string>}> */
    public function candidates(FamilyTree $tree, ?User $viewer, int $limit = 50): array
    {
        $byName = $this->health->people($tree, 'possible_duplicates')->get();
        $variants = $tree->people()->whereNotNull('alternate_name_search')->get();
        $tokens = $variants->flatMap(fn (Person $person) => array_filter(explode('|', (string) $person->alternate_name_search)))->unique()->values();
        $byVariant = $tokens->isEmpty() ? collect() : $tree->people()->whereIn('name_search', $tokens)->get();
        $people = $byName->merge($variants)->merge($byVariant)->unique('id')->keyBy('id');
        $fathers = PersonRelationship::query()->whereIn('person_id', $people->keys())->where('relationship_type', PersonRelationship::TYPE_FATHER)
            ->where('is_primary', true)->with('relatedPerson')->get()->keyBy('person_id');

        $pairs = [];
        $ordered = $people->values()->sortBy('id')->values();
        foreach ($ordered as $i => $a) {
            foreach ($ordered->slice($i + 1) as $b) {
                if (($fathers[$a->id]->related_person_id ?? null) !== ($fathers[$b->id]->related_person_id ?? null)) {
                    continue;
                }
                $sameName = $a->name_search === $b->name_search;
                $variant = str_contains((string) $a->alternate_name_search, '|'.$b->name_search.'|') || str_contains((string) $b->alternate_name_search, '|'.$a->name_search.'|');
                if (! $sameName && ! $variant) {
                    continue;
                }
                $pairs[] = ['a' => $a, 'b' => $b, 'reason' => $sameName ? 'الاسم نفسه بعد التطبيع' : 'أحد الاسمين تهجئة بديلة مسجلة للآخر', 'signals' => $this->signals($a, $b, $fathers[$a->id]->relatedPerson ?? null, $viewer)];
                if (count($pairs) >= $limit) {
                    return $pairs;
                }
            }
        }

        return $pairs;
    }

    /** @return array<int, string> */
    private function signals(Person $a, Person $b, ?Person $father, ?User $viewer): array
    {
        $signals = [$father ? 'الأب نفسه: '.$father->display_name : 'كلاهما دون أب مسجل'];
        foreach (['birth_year' => 'سنة الميلاد', 'death_year' => 'سنة الوفاة'] as $field => $label) {
            if ($a->{$field} && $b->{$field} && $this->privacy->canView($a, $field, $viewer) && $this->privacy->canView($b, $field, $viewer)) {
                $signals[] = $a->{$field} === $b->{$field} ? $label.' متطابقة' : $label.' مختلفة ('.$a->{$field}.' / '.$b->{$field}.')';
            }
        }
        if ($a->is_living !== null && $b->is_living !== null && $a->is_living !== $b->is_living) {
            $signals[] = 'حالة الحياة مختلفة';
        }

        return $signals;
    }
}
