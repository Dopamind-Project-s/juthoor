<?php

namespace App\Services\Genealogy;

use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrossFamilyLinkService
{
    /** Full validations a single page view may run for links changed since their last validation. */
    public const READ_REVALIDATION_BUDGET = 2;

    /** No depth truncation and no path across a hidden or unreviewed record. */
    public function path(Person $person, bool $public = false): Collection
    {
        $people = Person::query()->where('family_tree_id', $person->family_tree_id)
            ->when($public, fn ($q) => $q->verifiedHistorical())->with('personAttributes', 'familyTree.family', 'citations.source')->get()->keyBy('id');
        $edges = PersonRelationship::query()->where('family_tree_id', $person->family_tree_id)
            ->where('relationship_type', 'father')->where('is_primary', true)
            ->when($public, fn ($q) => $q->verified())->get()->keyBy('person_id');
        $result = collect();
        $seen = [];
        $id = $person->id;
        while ($id && isset($people[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $result->prepend($people[$id]);
            $id = $edges->get($id)?->related_person_id;
        }

        return $result;
    }

    public function eligible(?Person $person): bool
    {
        return $person && Person::query()->verifiedHistorical()->whereKey($person->id)
            ->whereHas('familyTree', fn ($q) => $q->active()->whereHas('family', fn ($q) => $q->active()))->exists();
    }

    /**
     * Whole participating trees are dependencies: conservative invalidation also catches new/reparented paths.
     * The encoded tree part is shared through $memo by links of the same trees checked together (one write's
     * re-check); the hash is byte-identical to encoding the whole list at once, so recorded hashes stay comparable.
     *
     * @param  array<string, string>  $memo
     */
    public function fingerprint(CrossFamilyLink $link, array &$memo = []): string
    {
        $ids = Person::query()->whereKey([$link->left_person_id, $link->right_person_id])->pluck('family_tree_id');
        $trees = $memo['trees:'.$ids->implode(',')] ??= implode(',', array_map(fn ($part) => json_encode($part, JSON_THROW_ON_ERROR), [
            FamilyTree::query()->whereKey($ids)->orderBy('id')->get()->toArray(),
            Person::query()->whereIn('family_tree_id', $ids)->with('sources', 'personAttributes')->orderBy('id')->get()->toArray(),
            PersonRelationship::query()->whereIn('family_tree_id', $ids)->with('source')->orderBy('id')->get()->toArray(),
            // Evidence for recorded claims (assertions) never changes the displayed genealogy, so it is not a dependency.
            Citation::query()->whereIn('family_tree_id', $ids)->whereNull('cross_family_link_id')->whereNull('assertion_id')->with('source')->orderBy('id')->get()->toArray(),
        ]));

        return hash('sha256', '['.json_encode($link->only(['left_person_id', 'right_person_id', 'kind', 'claim']), JSON_THROW_ON_ERROR)
            .','.$trees.','.json_encode($link->citations()->with('source')->orderBy('id')->get()->toArray(), JSON_THROW_ON_ERROR).']');
    }

    /**
     * Full validation: eligibility, approved evidence and the whole-tree fingerprint. Runs on writes (invalidator),
     * explicit revalidation and single-link review pages, never per link on list pages. The outcome is persisted.
     *
     * @param  array<string, string>  $memo  shared tree fingerprint parts while re-checking several links together
     */
    public function current(CrossFamilyLink $link, array &$memo = []): bool
    {
        if ($link->status !== 'approved') {
            return false;
        }
        // Taken before the checks, so a write landing during validation leaves the stamp stale rather than trusted.
        $stamp = $this->stamp($link);
        $valid = $this->eligible($link->leftPerson) && $this->eligible($link->rightPerson)
            && $link->citations()->whereIn('verification_status', ['verified', 'sourced'])
                ->whereHas('source', fn ($q) => $q->whereIn('verification_status', ['verified', 'sourced']))->exists()
            && hash_equals($link->dependency_hash ?? '', $this->fingerprint($link, $memo));
        if ($valid) {
            $this->recordValidation($link, $stamp);
        } else {
            DB::transaction(function () use ($link) {
                $locked = CrossFamilyLink::query()->lockForUpdate()->find($link->id);
                if ($locked?->status !== 'approved') {
                    return;
                }
                $before = app(AuditLogger::class)->snapshot($locked);
                $locked->update(['status' => 'needs_review', 'invalidation_reason' => 'تغيرت بيانات إحدى الشجرتين أو الأدلة أو صلاحية العرض؛ يلزم تأكيد جديد.', 'validated_at' => now(), 'validation_stamp' => null]);
                app(AuditLogger::class)->record(null, $locked->leftFamily, $locked, 'invalidated', $before, app(AuditLogger::class)->snapshot($locked));
            });
            $link->refresh();
        }

        return $valid;
    }

    /**
     * Read-side check without the full fingerprint: an approved link is trusted only while the aggregate stamp of its
     * dependencies equals the one recorded by its last successful full validation.
     *
     * @param  array<string, string>  $memo  per-request cache of tree/family aggregates shared by several links
     */
    public function trusted(CrossFamilyLink $link, array &$memo = []): bool
    {
        return $link->status === 'approved' && $link->validated_at !== null && $link->validation_stamp !== null
            && hash_equals($link->validation_stamp, $this->stamp($link, $memo) ?? '');
    }

    /** valid | stale (approved, awaiting revalidation) | needs_review | pending_review | rejected | disputed */
    public function validity(CrossFamilyLink $link, array &$memo = []): string
    {
        if ($link->status !== 'approved') {
            return $link->status;
        }

        return $this->trusted($link, $memo) ? 'valid' : 'stale';
    }

    /**
     * Cheap aggregate stamp (row counts, highest ids, latest update times) of everything the fingerprint covers.
     * Returns null while a dependency was written in the current second: a later write in that second could not be
     * told apart by second-precision timestamps, so such a validation is never trusted and is simply repeated later.
     *
     * @param  array<string, array<int, array{int, ?int, string}>>  $memo
     */
    public function stamp(CrossFamilyLink $link, array &$memo = []): ?string
    {
        $treeIds = Person::query()->withoutGlobalScopes()->whereKey(array_filter([$link->left_person_id, $link->right_person_id]))->pluck('family_tree_id')->unique()->sort()->values()->all();
        $parts = ['attributes' => $memo['attributes'] ??= [$this->aggregate(DB::table('person_attributes'))]];
        foreach ($treeIds as $treeId) {
            $parts['tree'.$treeId] = $memo['tree'.$treeId] ??= $this->treeStamp((int) $treeId);
        }
        foreach ([$link->left_family_id, $link->right_family_id] as $familyId) {
            $parts['family'.$familyId] = $memo['family'.$familyId] ??= [
                $this->aggregate(DB::table('families')->where('id', $familyId)),
                $this->aggregate(DB::table('sources')->where('family_id', $familyId)),
            ];
        }
        $parts['link'] = [$this->aggregate(DB::table('citations')->where('cross_family_link_id', $link->id))];
        $latest = collect($parts)->flatten(1)->max(fn (array $aggregate) => $aggregate[2]);
        if ($latest >= now()->format('Y-m-d H:i:s')) {
            return null;
        }

        return hash('sha256', json_encode([$link->only(['left_person_id', 'right_person_id', 'kind', 'status', 'dependency_hash']), $parts], JSON_THROW_ON_ERROR));
    }

    /**
     * Approved links that may be shown as verified. Trusted links cost a few aggregate queries; a stale link (written
     * since its last validation) is revalidated once, under a lock, within a small per-request budget, and otherwise
     * hidden for this request and revalidated after the response. A stale link is never shown as verified.
     *
     * @param  Collection<int, CrossFamilyLink>  $links
     * @return Collection<int, CrossFamilyLink>
     */
    public function verified(Collection $links, int $revalidationBudget = self::READ_REVALIDATION_BUDGET): Collection
    {
        $memo = [];

        return $links->filter(function (CrossFamilyLink $link) use (&$memo, &$revalidationBudget): bool {
            if ($this->trusted($link, $memo)) {
                return true;
            }
            if ($link->status !== 'approved') {
                return false;
            }
            $lock = Cache::lock('cross-family-link-validation:'.$link->id, 30);
            if ($revalidationBudget > 0 && $lock->get()) {
                $revalidationBudget--;
                try {
                    return $this->current($link);
                } finally {
                    $lock->release();
                }
            }
            dispatch(fn () => app(self::class)->revalidate($link))->afterResponse();

            return false;
        })->values();
    }

    /** Revalidates now (full check), for the explicit admin action and deferred read-side revalidation. */
    public function revalidate(CrossFamilyLink $link): string
    {
        $fresh = CrossFamilyLink::query()->find($link->id);
        if (! $fresh) {
            return 'missing';
        }
        $this->current($fresh);

        return $this->validity($fresh->refresh());
    }

    private function recordValidation(CrossFamilyLink $link, ?string $stamp): void
    {
        $values = ['validated_at' => now(), 'validation_stamp' => $stamp];
        // A plain query update: recording the outcome is not a genealogy change and must not re-trigger checks.
        CrossFamilyLink::query()->whereKey($link->id)->where('status', 'approved')->toBase()->update($values);
        $link->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    /**
     * People (including merged records), parent edges, tree citations, source and descriptor assignments of one tree.
     *
     * @return array<int, array{int, ?int, string}>
     */
    private function treeStamp(int $treeId): array
    {
        $people = DB::table('people')->where('family_tree_id', $treeId)->select('id');

        return [
            $this->aggregate(DB::table('family_trees')->where('id', $treeId)),
            $this->aggregate(DB::table('people')->where('family_tree_id', $treeId)),
            $this->aggregate(DB::table('person_relationships')->where('family_tree_id', $treeId)),
            $this->aggregate(DB::table('citations')->where('family_tree_id', $treeId)->whereNull('cross_family_link_id')->whereNull('assertion_id')),
            $this->aggregate(DB::table('person_sources')->whereIn('person_id', $people)),
            $this->aggregate(DB::table('person_attribute_assignments')->whereIn('person_id', $people), 'person_attribute_id'),
        ];
    }

    /** @return array{int, ?int, string} row count, sum of keys and latest update time of a table slice */
    private function aggregate(Builder $query, string $key = 'id'): array
    {
        $row = $query->selectRaw("count(*) as total, sum({$key}) as key_sum, max(updated_at) as latest")->first();

        return [(int) $row->total, $row->key_sum === null ? null : (int) $row->key_sum, $row->latest === null ? '' : Carbon::parse($row->latest)->format('Y-m-d H:i:s')];
    }

    public function review(CrossFamilyLink $link, User $reviewer, array $data): void
    {
        abort_unless($reviewer->isSuperAdmin() && $reviewer->hasVerifiedEmail(), 403);
        DB::transaction(function () use ($link, $reviewer, $data) {
            $link = CrossFamilyLink::query()->lockForUpdate()->findOrFail($link->id);
            // Serialize against normal genealogy writes, which lock their owning tree.
            FamilyTree::query()->whereIn('family_id', [$link->left_family_id, $link->right_family_id])->orderBy('id')->lockForUpdate()->get();
            $before = app(AuditLogger::class)->snapshot($link);
            if ($data['decision'] === 'approved') {
                if (empty($data['evidence_confirmed']) || ! $this->eligible($link->leftPerson) || ! $this->eligible($link->rightPerson)) {
                    throw ValidationException::withMessages(['decision' => 'يلزم تأكيد الأدلة وسجلان تاريخيان مسموح بعرضهما ومراجعان قبل الاعتماد.']);
                }
                if (! $link->citations()->whereNotNull('locator')->whereHas('source', fn ($q) => $q->whereNotNull('url'))->exists()) {
                    throw ValidationException::withMessages(['decision' => 'أضف مرجعًا محددًا ورابط مصدر وموضع استشهاد قبل الاعتماد.']);
                }
                $this->assertConsistent($link);
                foreach ($link->citations()->with('source')->get() as $citation) {
                    $citation->source->update(['verification_status' => 'sourced']);
                    $citation->update(['verification_status' => 'sourced']);
                }
            }
            $link->update(['status' => $data['decision'], 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_reason' => $data['reason'], 'invalidation_reason' => null]);
            $link->update(['dependency_hash' => $data['decision'] === 'approved' ? $this->fingerprint($link) : null, 'validated_at' => null, 'validation_stamp' => null]);
            if ($data['decision'] === 'approved') {
                $this->recordValidation($link, $this->stamp($link));
            }
            app(AuditLogger::class)->record($reviewer, $link->leftFamily, $link, 'reviewed', $before, app(AuditLogger::class)->snapshot($link));
        });
    }

    /** Treat identity links as equivalence only for cycle checking, never merge records. */
    private function assertConsistent(CrossFamilyLink $candidate): void
    {
        $links = CrossFamilyLink::query()->where('status', 'approved')->whereKeyNot($candidate->id)->get()->push($candidate);
        $groups = [];
        $find = function (int $id) use (&$groups): int {
            while (isset($groups[$id]) && $groups[$id] !== $id) {
                $id = $groups[$id];
            }

            return $id;
        };
        foreach ($links->where('kind', 'same_person') as $link) {
            if (! $link->left_person_id || ! $link->right_person_id) {
                continue;
            }
            $a = $find($link->left_person_id);
            $b = $find($link->right_person_id);
            if ($a !== $b) {
                $groups[$b] = $a;
            }
        }
        $edges = PersonRelationship::query()->verified()->where('relationship_type', 'father')->get(['person_id', 'related_person_id'])
            ->map(fn ($edge) => [$edge->related_person_id, $edge->person_id])->all();
        foreach ($links->where('kind', 'parent_child') as $link) {
            if ($link->left_person_id && $link->right_person_id) {
                $edges[] = [$link->left_person_id, $link->right_person_id];
            }
        }
        $children = [];
        $indegree = [];
        foreach ($edges as [$parent, $child]) {
            $parent = $find($parent);
            $child = $find($child);
            if (isset($children[$parent][$child])) {
                continue;
            }
            $children[$parent][$child] = true;
            $indegree[$parent] ??= 0;
            $indegree[$child] = ($indegree[$child] ?? 0) + 1;
        }
        $queue = array_keys(array_filter($indegree, fn ($degree) => $degree === 0));
        $visited = 0;
        for ($i = 0; $i < count($queue); $i++) {
            $visited++;
            foreach (array_keys($children[$queue[$i]] ?? []) as $child) {
                if (--$indegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }
        if ($visited !== count($indegree)) {
            throw ValidationException::withMessages(['decision' => 'تؤدي المطابقة إلى دورة نسب أو مطابقة شخص بأحد أسلافه. راجع الروابط القائمة.']);
        }
    }

    /** Public descendants stop at hidden/unverified records; no implied bridge across a gap. */
    public function descendants(Person $person): Collection
    {
        return $this->descendantsOf(collect([$person]))[$person->id];
    }

    /**
     * Descendants of several founders, loading each participating tree once.
     *
     * @param  Collection<int, Person>  $founders
     * @return array<int, Collection<int, Person>> keyed by founder id
     */
    public function descendantsOf(Collection $founders): array
    {
        $result = [];
        foreach ($founders->filter()->groupBy('family_tree_id') as $treeId => $treeFounders) {
            $people = Person::query()->verifiedHistorical()->where('family_tree_id', $treeId)->with('personAttributes', 'familyTree.family')->get()->keyBy('id');
            $edges = PersonRelationship::query()->verified()->where('relationship_type', 'father')->where('family_tree_id', $treeId)->get()->groupBy('related_person_id');
            $parents = $edges->flatten()->keyBy('person_id');
            foreach ($treeFounders as $founder) {
                $result[$founder->id] ??= $this->walk($founder->id, $people, $edges, $parents);
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, Person>  $people
     * @param  Collection<int, Collection<int, PersonRelationship>>  $edges
     * @param  Collection<int, PersonRelationship>  $parents
     * @return Collection<int, Person>
     */
    private function walk(int $founderId, Collection $people, Collection $edges, Collection $parents): Collection
    {
        $queue = [$founderId];
        $seen = [];
        $result = collect();
        for ($i = 0; $i < count($queue); $i++) {
            $id = $queue[$i];
            if (isset($seen[$id]) || ! $people->has($id)) {
                continue;
            }
            $seen[$id] = true;
            $people[$id]->setRelation('sharedFather', $people->get($parents->get($id)?->related_person_id));
            $result->push($people[$id]);
            foreach ($edges->get($id, collect()) as $edge) {
                $queue[] = $edge->person_id;
            }
        }

        return $result;
    }
}
