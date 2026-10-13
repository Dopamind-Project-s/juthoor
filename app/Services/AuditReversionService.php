<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Genealogy\GenealogyMutationService;
use Illuminate\Support\Facades\DB;

class AuditReversionService
{
    public function __construct(private GenealogyMutationService $mutations, private AuditLogger $audit) {}

    public function preview(AuditLog $log): array
    {
        $kind = match ($log->auditable_type) {
            Person::class => 'person',PersonRelationship::class => 'relationship',Branch::class => 'branch',default => null
        };
        if (! $kind || ! in_array($log->action, ['created', 'updated'], true) || ! $log->after) {
            $this->mutations->reject('هذا التغيير غير مدعوم للاسترجاع. يمكن التراجع عن إنشاء أو تعديل الأشخاص والأبوة والفروع فقط؛ الحذف لا يعيد اعتمادياته تلقائيًا.');
        }
        $tree = FamilyTree::query()->where('family_id', $log->family_id)->findOrFail($log->after['family_tree_id']);
        if (! $this->mutations->modelClass($kind)::query()->where('family_tree_id', $tree->id)->whereKey($log->auditable_id)->exists()) {
            $this->mutations->reject('سجل العملية محذوف؛ لا يمكن استرجاعه تلقائيًا مع اعتمادياته.');
        }
        $record = $this->mutations->target($tree, $kind, $log->auditable_id);
        $latest = AuditLog::query()->where('auditable_type', $log->auditable_type)->where('auditable_id', $log->auditable_id)->max('id');
        if ((int) $latest !== $log->id || array_intersect_key($this->audit->snapshot($record), $log->after) != $log->after || AuditLog::query()->where('reverted_from_id', $log->id)->exists()) {
            $this->mutations->reject('تعذر الاسترجاع: السجل تغير لاحقًا أو سبق استرجاع هذا التغيير.');
        }
        $operation = $log->action === 'created' ? 'delete' : 'update';
        $data = [];
        if ($operation === 'delete') {
            $this->mutations->assertDeletable($record);
        } else {
            if (! $log->before) {
                $this->mutations->reject('القيم السابقة غير متاحة.');
            }
            $data = array_intersect_key($log->before, array_flip($record->getFillable()));
            unset($data['family_tree_id']);
            $this->mutations->attributes($tree, $kind, $data, $record);
            $candidate = clone $record;
            $candidate->fill($data);
            $this->mutations->validateModel($candidate);
        }

        $referenceLabels = $this->mutations->referenceLabels($tree, $log->after, $log->before ?? []);

        return compact('tree', 'record', 'kind', 'operation', 'data', 'referenceLabels');
    }

    public function revert(AuditLog $log, User $user, string $reason): AuditLog
    {
        return DB::transaction(function () use ($log, $user, $reason) {
            $treeId = $log->after['family_tree_id'] ?? null;
            FamilyTree::query()->whereKey($treeId)->lockForUpdate()->firstOrFail();
            $log = AuditLog::query()->lockForUpdate()->findOrFail($log->id);
            $context = $this->preview($log);
            $new = $this->mutations->apply($context['tree'], $user, $context['kind'], $context['operation'], $context['data'], $context['record']);
            $new->update(['action' => 'reverted', 'reverted_from_id' => $log->id, 'reason' => $reason]);

            return $new;
        });
    }
}
