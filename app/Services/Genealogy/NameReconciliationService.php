<?php

namespace App\Services\Genealogy;

use App\Models\Assertion;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\PersonRelationship;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Review queue for the historical ROOT name differences recorded by RootVariantImporter. It only reads: every decision
 * goes through the existing assertion actions (classify, cite, propose → change review, decline), so nothing here
 * resolves a difference on its own and an unresolved difference stays visible until a reviewer decides it.
 */
class NameReconciliationService
{
    public const IMPORT_PREFIX = 'root-variant:';

    public const DATASETS = [
        'dataset' => 'مجموعة البيانات المنسقة (الإصدار الرابع)',
        'shared' => 'تسلسل الجذر المشترك المقدم في 2026-10-16',
    ];

    public const FILTERS = [
        'open' => 'بانتظار المراجعة',
        'unresolved' => 'محل خلاف (مُبقاة دون حسم)',
        'decided' => 'تم البت فيها',
        'all' => 'الكل',
    ];

    /** @return array<string, int> */
    public function summary(FamilyTree $tree): array
    {
        $claims = $tree->assertions()->where('import_key', 'like', self::IMPORT_PREFIX.'%')->get(['state', 'verification_status']);

        return [
            'total' => $claims->count(),
            'open' => $claims->where('state', 'proposed')->where('verification_status', '!=', 'unresolved')->count(),
            'unresolved' => $claims->where('state', 'proposed')->where('verification_status', 'unresolved')->count(),
            'alternative' => $claims->where('state', 'alternative')->count(),
            'authoritative' => $claims->where('state', 'authoritative')->count(),
            'rejected' => $claims->where('state', 'rejected')->count(),
        ];
    }

    /**
     * @return Collection<int, array{claim: Assertion, dataset: string, competing: Collection<int, Assertion>, branches: Collection<int, Branch>, sharedFamilies: Collection<int, Family>, pendingChange: ?ChangeRequest, history: Collection<int, AuditLog>}>
     */
    public function rows(FamilyTree $tree, User $user, string $filter = 'open'): Collection
    {
        $claims = $tree->assertions()->where('import_key', 'like', self::IMPORT_PREFIX.'%')
            ->when($filter === 'open', fn ($query) => $query->where('state', 'proposed')->where('verification_status', '!=', 'unresolved'))
            ->when($filter === 'unresolved', fn ($query) => $query->where('state', 'proposed')->where('verification_status', 'unresolved'))
            ->when($filter === 'decided', fn ($query) => $query->where('state', '!=', 'proposed'))
            ->with(['person' => fn ($query) => $query->withoutGlobalScope('active'), 'citations.source', 'reviewer', 'changeRequests' => fn ($query) => $query->latest('id')])
            ->get()
            ->sortBy(fn (Assertion $claim) => [$claim->person?->canonical_key ?? '', $claim->import_key])
            ->values();
        $personIds = $claims->pluck('person_id')->unique()->all();
        $competing = Assertion::query()->whereIn('person_id', $personIds)->whereIn('fact_type', ['full_name', 'alternate_name'])
            ->with('citations.source')->orderBy('id')->get()->groupBy('person_id');
        $fathers = PersonRelationship::query()->where('family_tree_id', $tree->id)->where('relationship_type', PersonRelationship::TYPE_FATHER)
            ->where('is_primary', true)->pluck('related_person_id', 'person_id')->all();
        $branches = $this->branchesByAncestor($tree, $fathers);
        $shared = $this->sharedFamiliesByAncestor($tree, $fathers);
        $history = $this->history($tree, $user, $claims);

        return $claims->map(fn (Assertion $claim) => [
            'claim' => $claim,
            'dataset' => self::DATASETS[explode(':', (string) $claim->import_key)[1] ?? ''] ?? 'غير معروف',
            'competing' => ($competing[$claim->person_id] ?? collect())->reject(fn (Assertion $other) => $other->id === $claim->id)->values(),
            'branches' => $branches[$claim->person_id] ?? collect(),
            'sharedFamilies' => $shared[$claim->person_id] ?? collect(),
            'pendingChange' => $claim->changeRequests->firstWhere('status', 'pending'),
            'history' => $history[$claim->id] ?? collect(),
        ]);
    }

    /**
     * Branches whose founder is the person or descends from them: renaming the person changes their lineage text.
     *
     * @param  array<int, int>  $fathers
     * @return array<int, Collection<int, Branch>>
     */
    private function branchesByAncestor(FamilyTree $tree, array $fathers): array
    {
        $map = [];
        foreach (Branch::query()->where('family_tree_id', $tree->id)->whereNotNull('root_person_id')->orderBy('name_ar')->get() as $branch) {
            foreach ($this->lineage($branch->root_person_id, $fathers) as $ancestorId) {
                $map[$ancestorId] ??= collect();
                $map[$ancestorId]->push($branch);
            }
        }

        return $map;
    }

    /**
     * Mirrors SharedAncestryScope for the whole queue in one pass.
     *
     * @param  array<int, int>  $fathers
     * @return array<int, Collection<int, Family>>
     */
    private function sharedFamiliesByAncestor(FamilyTree $tree, array $fathers): array
    {
        $map = [];
        $links = CrossFamilyLink::query()->whereIn('status', ['approved', 'needs_review'])
            ->where(fn ($query) => $query->where('left_family_id', $tree->family_id)->orWhere('right_family_id', $tree->family_id))
            ->with(['leftPerson', 'rightPerson', 'leftFamily', 'rightFamily'])->get();
        foreach ($links as $link) {
            foreach ([$link->leftPerson, $link->rightPerson] as $endpoint) {
                if ($endpoint?->family_tree_id !== $tree->id) {
                    continue;
                }
                foreach ($this->lineage($endpoint->id, $fathers) as $ancestorId) {
                    $map[$ancestorId] ??= collect();
                    $map[$ancestorId]->push($link->leftFamily, $link->rightFamily);
                }
            }
        }

        return array_map(fn (Collection $families) => $families->filter()->unique('id')->values(), $map);
    }

    /**
     * @param  array<int, int>  $fathers
     * @return array<int, int> the person and their ancestors
     */
    private function lineage(int $personId, array $fathers): array
    {
        $ids = [];
        for ($current = $personId; $current !== null && ! isset($ids[$current]); $current = $fathers[$current] ?? null) {
            $ids[$current] = $current;
        }

        return array_values($ids);
    }

    /**
     * Decisions about each claim: its own audit entries, its evidence and the change requests that tried to apply it.
     *
     * @param  Collection<int, Assertion>  $claims
     * @return Collection<int, Collection<int, AuditLog>>
     */
    private function history(FamilyTree $tree, User $user, Collection $claims): Collection
    {
        $citationOwners = Citation::query()->whereIn('assertion_id', $claims->pluck('id'))->pluck('assertion_id', 'id');
        $changeOwners = ChangeRequest::query()->whereIn('assertion_id', $claims->pluck('id'))->pluck('assertion_id', 'id');
        $logs = AuditLog::query()->visibleToMember($user, $tree->family)->with('user')
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('auditable_type', (new Assertion)->getMorphClass())->whereIn('auditable_id', $claims->pluck('id')))
                ->orWhere(fn ($q) => $q->where('auditable_type', (new Citation)->getMorphClass())->whereIn('auditable_id', $citationOwners->keys()))
                ->orWhere(fn ($q) => $q->where('auditable_type', (new ChangeRequest)->getMorphClass())->whereIn('auditable_id', $changeOwners->keys())))
            ->orderBy('id')->get();

        return $logs->groupBy(fn (AuditLog $log) => match ($log->auditable_type) {
            (new Citation)->getMorphClass() => $citationOwners[$log->auditable_id],
            (new ChangeRequest)->getMorphClass() => $changeOwners[$log->auditable_id],
            default => $log->auditable_id,
        });
    }
}
