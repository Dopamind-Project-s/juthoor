<?php

namespace App\Services\Genealogy;

use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrossFamilyLinkService
{
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

    /** Whole participating trees are dependencies: conservative invalidation also catches new/reparented paths. */
    public function fingerprint(CrossFamilyLink $link): string
    {
        $ids = Person::query()->whereKey([$link->left_person_id, $link->right_person_id])->pluck('family_tree_id');

        return hash('sha256', json_encode([
            $link->only(['left_person_id', 'right_person_id', 'kind', 'claim']),
            FamilyTree::query()->whereKey($ids)->orderBy('id')->get()->toArray(),
            Person::query()->whereIn('family_tree_id', $ids)->with('sources', 'personAttributes')->orderBy('id')->get()->toArray(),
            PersonRelationship::query()->whereIn('family_tree_id', $ids)->with('source')->orderBy('id')->get()->toArray(),
            Citation::query()->whereIn('family_tree_id', $ids)->whereNull('cross_family_link_id')->with('source')->orderBy('id')->get()->toArray(),
            $link->citations()->with('source')->orderBy('id')->get()->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    public function current(CrossFamilyLink $link): bool
    {
        if ($link->status !== 'approved') {
            return false;
        }
        $valid = $this->eligible($link->leftPerson) && $this->eligible($link->rightPerson)
            && $link->citations()->whereIn('verification_status', ['verified', 'sourced'])
                ->whereHas('source', fn ($q) => $q->whereIn('verification_status', ['verified', 'sourced']))->exists()
            && hash_equals($link->dependency_hash ?? '', $this->fingerprint($link));
        if (! $valid) {
            DB::transaction(function () use ($link) {
                $locked = CrossFamilyLink::query()->lockForUpdate()->find($link->id);
                if ($locked?->status !== 'approved') {
                    return;
                }
                $before = app(AuditLogger::class)->snapshot($locked);
                $locked->update(['status' => 'needs_review', 'invalidation_reason' => 'تغيرت بيانات إحدى الشجرتين أو الأدلة أو صلاحية العرض؛ يلزم تأكيد جديد.']);
                app(AuditLogger::class)->record(null, $locked->leftFamily, $locked, 'invalidated', $before, app(AuditLogger::class)->snapshot($locked));
            });
        }

        return $valid;
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
            $link->update(['dependency_hash' => $data['decision'] === 'approved' ? $this->fingerprint($link) : null]);
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
        $people = Person::query()->verifiedHistorical()->where('family_tree_id', $person->family_tree_id)->with('personAttributes', 'familyTree.family')->get()->keyBy('id');
        $edges = PersonRelationship::query()->verified()->where('relationship_type', 'father')->where('family_tree_id', $person->family_tree_id)->get()->groupBy('related_person_id');
        $parents = $edges->flatten()->keyBy('person_id');
        $queue = [$person->id];
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
