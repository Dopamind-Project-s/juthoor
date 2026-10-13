<?php

namespace App\Services;

use App\Models\ChangeRequest;
use App\Models\FamilyTree;
use App\Models\User;
use App\Services\Genealogy\GenealogyMutationService;
use Illuminate\Support\Facades\DB;

class ChangeReviewService
{
    public function __construct(private GenealogyMutationService $mutations, private AuditLogger $audit) {}

    public function submit(FamilyTree $tree, User $user, string $kind, string $operation, ?int $targetId, array $input, string $reason): ChangeRequest
    {
        return DB::transaction(function () use ($tree, $user, $kind, $operation, $targetId, $input, $reason) {
            FamilyTree::query()->whereKey($tree->id)->lockForUpdate()->firstOrFail();
            $record = $operation === 'update' ? $this->mutations->target($tree, $kind, $targetId) : null;
            $payload = $this->mutations->attributes($tree, $kind, $input, $record);
            $candidate = $record ? clone $record : new ($this->mutations->modelClass($kind));
            $candidate->fill($payload);
            $candidate->family_tree_id = $tree->id;
            $this->mutations->validateModel($candidate);
            $change = ChangeRequest::query()->create(['family_tree_id' => $tree->id, 'submitted_by' => $user->id, 'target_type' => $kind, 'target_id' => $record?->id, 'operation' => $operation, 'payload' => $payload, 'base' => $record ? $this->audit->snapshot($record) : null, 'base_fingerprint' => $record ? $this->mutations->fingerprint($record) : null, 'reason' => $reason]);
            $this->audit->record($user, $tree->family, $change, 'submitted', null, $this->audit->snapshot($change));

            return $change;
        });
    }

    public function decide(FamilyTree $tree, ChangeRequest $change, User $user, string $decision, string $reason): void
    {
        DB::transaction(function () use ($tree, $change, $user, $decision, $reason): void {
            FamilyTree::query()->whereKey($tree->id)->lockForUpdate()->firstOrFail();
            $change = ChangeRequest::query()->where('family_tree_id', $tree->id)->lockForUpdate()->findOrFail($change->id);
            if ($change->status !== 'pending') {
                $this->mutations->reject('سبق اتخاذ قرار بشأن هذا الطلب.');
            }
            if ($change->submitted_by === $user->id) {
                $this->mutations->reject('لا يمكنك مراجعة مقترحك بنفسك؛ يلزم مدير آخر.');
            }
            $before = $this->audit->snapshot($change);
            $log = null;
            if ($decision === 'accepted') {
                if ($change->operation === 'update' && ! $this->mutations->modelClass($change->target_type)::query()->where('family_tree_id', $tree->id)->whereKey($change->target_id)->exists()) {
                    $this->mutations->reject('حُذف السجل منذ تقديم المقترح؛ لا يمكن اعتماده.');
                }
                $record = $change->operation === 'update' ? $this->mutations->target($tree, $change->target_type, $change->target_id) : null;
                if ($record && ! hash_equals($change->base_fingerprint, $this->mutations->fingerprint($record))) {
                    $this->mutations->reject('تغير السجل منذ تقديم المقترح. أعد تقديمه بناءً على النسخة الحالية.');
                }
                $data = $this->mutations->attributes($tree, $change->target_type, $change->payload, $record);
                $log = $this->mutations->apply($tree, $user, $change->target_type, $change->operation, $data, $record);
            }
            $change->update(['status' => $decision, 'decision_reason' => $reason, 'reviewed_by' => $user->id, 'decided_at' => now(), 'applied_audit_id' => $log?->id]);
            $this->audit->record($user, $tree->family, $change, $decision, $before, $this->audit->snapshot($change));
        });
    }
}
