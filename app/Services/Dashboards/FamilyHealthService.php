<?php

namespace App\Services\Dashboards;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Services\Genealogy\LivingStatusClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Tree-scoped data quality checks shared by the family dashboard and the filtered people list.
 * Every query is constrained to one family tree on the server.
 */
class FamilyHealthService
{
    /** @var array<string, string> */
    public const ISSUES = [
        'without_father' => 'أشخاص دون أب موثق',
        'without_sources' => 'أشخاص دون مصدر أو استشهاد',
        'possible_duplicates' => 'تكرار محتمل (الاسم نفسه والأب نفسه)',
        'living_unknown' => 'حالة الحياة غير معروفة (غير منشورة)',
        'living_legacy' => 'حالة حياة سابقة لنظام الحالات الثلاث تحتاج مراجعة',
        'unresolved' => 'سجلات غير محسومة',
    ];

    /** @return Builder<Person> */
    public function people(FamilyTree $tree, string $issue): Builder
    {
        $query = Person::query()->where('people.family_tree_id', $tree->id);

        return match ($issue) {
            'without_father' => $query->when($tree->root_person_id, fn ($query) => $query->whereKeyNot($tree->root_person_id))
                ->whereDoesntHave('outgoingRelationships', fn ($query) => $query->where('relationship_type', 'father')->where('is_primary', true)),
            'without_sources' => $query->whereDoesntHave('citations')->whereDoesntHave('sources'),
            'possible_duplicates' => $query->whereIn('people.id', $this->duplicatePeopleIds($tree)),
            'living_unknown' => $query->whereNull('is_living'),
            'living_legacy' => $query->where('living_status_basis', LivingStatusClassifier::BASIS_LEGACY),
            'unresolved' => $query->where(fn ($query) => $query->where('status', Person::STATUS_UNRESOLVED)->orWhere('verification_status', 'unresolved')),
            default => abort(422),
        };
    }

    /**
     * @return array<int, array{key: string, label: string, count: int, level: string}>
     */
    public function indicators(FamilyTree $tree): array
    {
        $indicators = [];
        foreach (self::ISSUES as $key => $label) {
            $count = $this->people($tree, $key)->count();
            $indicators[] = ['key' => $key, 'label' => $label, 'count' => $count, 'level' => $count ? 'warning' : 'ok'];
        }

        $pendingRelationships = $tree->personRelationships()->whereIn('verification_status', ['pending_review', 'unresolved'])->count();
        $indicators[] = ['key' => 'pending_relationships', 'label' => 'علاقات بانتظار التوثيق', 'count' => $pendingRelationships, 'level' => $pendingRelationships ? 'warning' : 'ok'];

        $crossTree = $this->crossTreeRelationships($tree);
        $indicators[] = ['key' => 'cross_tree_relationships', 'label' => 'علاقات مكسورة تشير إلى شجرة أخرى', 'count' => $crossTree, 'level' => $crossTree ? 'danger' : 'ok'];

        $foundless = $tree->branches()->whereNull('root_person_id')->count();
        $indicators[] = ['key' => 'branches_without_founder', 'label' => 'فروع دون مؤسس', 'count' => $foundless, 'level' => $foundless ? 'warning' : 'ok'];

        return $indicators;
    }

    /**
     * Same name under the same primary father (or both without a father) inside one tree.
     *
     * @return array<int, array{name: string, father: ?string, count: int}>
     */
    public function duplicateGroups(FamilyTree $tree, int $limit = 5): array
    {
        return Person::query()
            ->where('people.family_tree_id', $tree->id)
            ->whereNotNull('people.name_search')
            ->leftJoin('person_relationships as father_edge', fn ($join) => $join->on('father_edge.person_id', '=', 'people.id')
                ->where('father_edge.relationship_type', 'father')->where('father_edge.is_primary', true))
            ->leftJoin('people as father', 'father.id', '=', 'father_edge.related_person_id')
            ->groupBy('people.name_search', 'father_edge.related_person_id')
            ->havingRaw('count(*) > 1')
            ->orderByRaw('count(*) desc')
            ->limit($limit)
            ->selectRaw('min(people.full_name_ar) as name, min(father.full_name_ar) as father, count(*) as total')
            ->toBase()->get()
            ->map(fn ($row) => ['name' => $row->name, 'father' => $row->father, 'count' => (int) $row->total])
            ->all();
    }

    private function crossTreeRelationships(FamilyTree $tree): int
    {
        return PersonRelationship::query()
            ->where('person_relationships.family_tree_id', $tree->id)
            ->join('people as child', 'child.id', '=', 'person_relationships.person_id')
            ->join('people as parent', 'parent.id', '=', 'person_relationships.related_person_id')
            ->where(fn ($query) => $query->whereColumn('child.family_tree_id', '<>', 'person_relationships.family_tree_id')
                ->orWhereColumn('parent.family_tree_id', '<>', 'person_relationships.family_tree_id'))
            ->count();
    }

    /**
     * People sharing a normalized name and the same primary father (or both fatherless).
     * One grouped pass over the tree instead of a correlated lookup per person.
     */
    private function duplicatePeopleIds(FamilyTree $tree): QueryBuilder
    {
        $keyed = fn (string $alias) => DB::table("people as {$alias}")
            ->leftJoin("person_relationships as {$alias}_father", fn ($join) => $join->on("{$alias}_father.person_id", '=', "{$alias}.id")
                ->where("{$alias}_father.relationship_type", 'father')->where("{$alias}_father.is_primary", true))
            ->where("{$alias}.family_tree_id", $tree->id)
            ->whereNotNull("{$alias}.name_search");
        $groups = $keyed('grouped')
            ->selectRaw('grouped.name_search, coalesce(grouped_father.related_person_id, 0) as father_key')
            ->groupBy('grouped.name_search', DB::raw('coalesce(grouped_father.related_person_id, 0)'))
            ->havingRaw('count(*) > 1');

        return $keyed('candidate')
            ->joinSub($groups, 'duplicate_groups', fn ($join) => $join->on('duplicate_groups.name_search', '=', 'candidate.name_search')
                ->whereRaw('duplicate_groups.father_key = coalesce(candidate_father.related_person_id, 0)'))
            ->select('candidate.id');
    }
}
