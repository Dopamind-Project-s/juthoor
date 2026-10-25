<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use App\Services\Genealogy\GenealogyMutationService;
use App\Services\Genealogy\PersonPrivacy;
use App\Services\Genealogy\PublicationGuard;
use App\Services\Genealogy\SharedAncestryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recovery of genealogy changes (JTH-09). Created/updated records are reverted through the governed mutation path;
 * deleted people, relationships and branches are restored under their original id from the deletion snapshot.
 * Every recovery is a new audit entry linked by the unique reverted_from_id, so an event is recovered at most once.
 * Merges use their own journal (Phase 2.3) and accepted claims are reversed by re-proposing the superseded claim.
 */
class AuditReversionService
{
    private const KINDS = [Person::class => 'person', PersonRelationship::class => 'relationship', Branch::class => 'branch'];

    /** Snapshot keys without which a deletion cannot be restored faithfully. */
    private const REQUIRED = [
        'person' => ['id', 'family_tree_id', 'first_name_ar', 'full_name_ar', 'status', 'verification_status'],
        'relationship' => ['id', 'family_tree_id', 'person_id', 'related_person_id', 'relationship_type', 'is_primary', 'verification_status'],
        'branch' => ['id', 'family_tree_id', 'root_person_id', 'name_ar', 'slug', 'verification_status'],
    ];

    public function __construct(private GenealogyMutationService $mutations, private AuditLogger $audit, private PublicationGuard $publication) {}

    /** @return array{eligible: bool, label: string, reason: ?string} */
    public function eligibility(AuditLog $log): array
    {
        $label = $log->action === 'deleted' ? 'استعادة المحذوف' : 'استرجاع التغيير';
        try {
            $this->preview($log);

            return ['eligible' => true, 'label' => $label, 'reason' => null];
        } catch (ValidationException $exception) {
            return ['eligible' => false, 'label' => $label, 'reason' => collect($exception->errors())->flatten()->first()];
        }
    }

    /** Read-only; throws a ValidationException with the blocker when recovery is not possible. */
    public function preview(AuditLog $log): array
    {
        $kind = self::KINDS[$log->auditable_type] ?? null;
        if (in_array($log->action, ['merged', 'merge_undone'], true)) {
            $this->mutations->reject('عمليات الدمج تُسترجع من صفحة عملية الدمج نفسها، لا من سجل التدقيق.');
        }
        if (! $kind || ! in_array($log->action, ['created', 'updated', 'deleted'], true)) {
            $this->mutations->reject('هذا الحدث تاريخي فقط ولا يُسترجع آليًا. يمكن استرجاع إنشاء الأشخاص والأبوة والفروع وتعديلها وحذفها.');
        }
        if (AuditLog::query()->where('reverted_from_id', $log->id)->exists()) {
            $this->mutations->reject('سبق استرجاع هذه العملية.');
        }
        if (ChangeRequest::query()->where('applied_audit_id', $log->id)->whereNotNull('assertion_id')->exists()) {
            $this->mutations->reject('طُبّق هذا التغيير باعتماد رواية. للتراجع اقترح اعتماد الرواية السابقة (المستبدلة) من لوحة الوقائع ليمر بالمراجعة.');
        }

        return $log->action === 'deleted' ? $this->previewRestore($log, $kind) : $this->previewRevert($log, $kind);
    }

    public function revert(AuditLog $log, User $user, string $reason, ?string $fingerprint = null, bool $confirmScope = false): AuditLog
    {
        return DB::transaction(function () use ($log, $user, $reason, $fingerprint, $confirmScope) {
            $snapshot = $log->after ?? $log->before ?? [];
            if (isset($snapshot['family_tree_id'])) {
                FamilyTree::query()->whereKey($snapshot['family_tree_id'])->lockForUpdate()->first();
            }
            $log = AuditLog::query()->lockForUpdate()->findOrFail($log->id);
            $context = $this->preview($log);
            if ($fingerprint !== null && ! hash_equals($context['fingerprint'], $fingerprint)) {
                $this->mutations->reject('تغيرت حالة السجل منذ المعاينة. أعد المعاينة قبل التأكيد.');
            }
            if (($context['publishes'] || $context['broadens'] !== []) && ! $confirmScope) {
                $this->mutations->reject('الاسترجاع يوسّع النشر أو الخصوصية؛ أكّد ذلك صراحةً بعد المراجعة.');
            }
            if ($context['operation'] === 'restore') {
                return $this->restore($context, $log, $user, $reason);
            }
            $new = $this->mutations->apply($context['tree'], $user, $context['kind'], $context['operation'], $context['data'], $context['record']);
            $new->update(['action' => 'reverted', 'reverted_from_id' => $log->id, 'reason' => $reason]);

            return $new;
        });
    }

    /** @return Collection<int, AuditLog> deleted relationships and branches that referenced a deleted person */
    public function relatedDeletions(AuditLog $log): Collection
    {
        $id = (int) $log->auditable_id;

        return AuditLog::query()->where('family_id', $log->family_id)->where('action', 'deleted')
            ->whereIn('auditable_type', [PersonRelationship::class, Branch::class])->whereNotIn('id', AuditLog::query()->whereNotNull('reverted_from_id')->select('reverted_from_id'))
            ->latest('id')->limit(200)->get()
            ->filter(fn (AuditLog $other) => in_array($id, array_map('intval', array_filter([$other->before['person_id'] ?? null, $other->before['related_person_id'] ?? null, $other->before['root_person_id'] ?? null])), true))
            ->values();
    }

    /** @return array<string, mixed> */
    private function previewRevert(AuditLog $log, string $kind): array
    {
        if (! $log->after) {
            $this->mutations->reject('القيم اللاحقة غير متاحة في هذا السجل القديم؛ لا يمكن التحقق من الحالة الحالية.');
        }
        $tree = FamilyTree::query()->where('family_id', $log->family_id)->findOrFail($log->after['family_tree_id']);
        if (! $this->mutations->modelClass($kind)::query()->where('family_tree_id', $tree->id)->whereKey($log->auditable_id)->exists()) {
            $this->mutations->reject('سجل العملية محذوف أو مدموج حاليًا؛ استخدم استعادة عملية الحذف أو صفحة الدمج.');
        }
        $record = $this->mutations->target($tree, $kind, $log->auditable_id);
        $latest = AuditLog::query()->where('auditable_type', $log->auditable_type)->where('auditable_id', $log->auditable_id)->max('id');
        if ((int) $latest !== $log->id || array_intersect_key($this->audit->snapshot($record), $log->after) != $log->after) {
            $this->mutations->reject('تعذر الاسترجاع: السجل تغير بعد هذه العملية. يُسترجع آخر تغيير فقط حتى لا يُكتب فوق عمل أحدث.');
        }
        $operation = $log->action === 'created' ? 'delete' : 'update';
        $data = [];
        $candidate = null;
        if ($operation === 'delete') {
            $this->mutations->assertDeletable($record);
        } else {
            if (! $log->before) {
                $this->mutations->reject('القيم السابقة غير متاحة.');
            }
            $data = array_intersect_key($log->before, array_flip($record->getFillable()));
            // Snapshots keep JSON columns as stored strings; the governed path validates them as arrays.
            foreach (['field_visibility', 'alternate_names'] as $json) {
                if (isset($data[$json]) && is_string($data[$json])) {
                    $data[$json] = json_decode($data[$json], true);
                }
            }
            if ($record instanceof Person && array_key_exists('attribute_ids', $log->before)) {
                $data['attribute_ids'] = $log->before['attribute_ids'];
            }
            unset($data['family_tree_id']);
            $this->mutations->attributes($tree, $kind, $data, $record);
            $candidate = clone $record;
            $candidate->fill(array_diff_key($data, ['attribute_ids' => true]));
            $this->mutations->validateModel($candidate);
        }

        return [
            'tree' => $tree, 'record' => $record, 'kind' => $kind, 'operation' => $operation, 'data' => $data,
            'current' => $this->audit->snapshot($record), 'proposed' => $operation === 'delete' ? [] : array_replace($this->audit->snapshot($record), $data),
            'referenceLabels' => $this->mutations->referenceLabels($tree, $log->after, $log->before ?? []),
            'publishes' => $candidate !== null && ! $this->isPublic($record, $record->getAttributes()) && $this->isPublic($candidate, $candidate->getAttributes()),
            'broadens' => $candidate instanceof Person ? $this->broadenedFields($record, $candidate) : [],
            'sharedFamilies' => app(SharedAncestryScope::class)->impactedFamilies($record),
            'unpublished' => false, 'relatedDeletions' => collect(),
            'fingerprint' => $this->fingerprint($log, $kind, (int) $log->auditable_id),
        ];
    }

    /** @return array<string, mixed> */
    private function previewRestore(AuditLog $log, string $kind): array
    {
        $snapshot = $log->before;
        if (! is_array($snapshot) || array_diff(self::REQUIRED[$kind], array_keys($snapshot)) !== []) {
            $this->mutations->reject('لا تحتوي عملية الحذف هذه على لقطة كاملة؛ لا تُستعاد بقيم مختلقة.');
        }
        $tree = FamilyTree::query()->where('family_id', $log->family_id)->find($snapshot['family_tree_id'])
            ?? $this->mutations->reject('شجرة السجل المحذوف لم تعد موجودة.');
        $class = $this->mutations->modelClass($kind);
        $exists = $kind === 'person' ? Person::query()->withoutGlobalScope('active')->whereKey($snapshot['id'])->exists() : $class::query()->whereKey($snapshot['id'])->exists();
        if ($exists) {
            $this->mutations->reject('يوجد سجل بالمعرّف نفسه؛ ربما استُعيد سابقًا.');
        }
        $record = $this->restoredModel($kind, $snapshot);
        $blockers = match ($kind) {
            'person' => $this->personRestoreBlockers($tree, $record),
            'relationship' => $this->relationshipRestoreBlockers($tree, $record),
            'branch' => $this->branchRestoreBlockers($tree, $record),
        };
        if ($blockers !== []) {
            throw ValidationException::withMessages(['change' => $blockers]);
        }
        try {
            $this->mutations->validateModel($record);
        } catch (ValidationException) {
            $this->mutations->reject('الاستعادة تخالف سلامة الأنساب الحالية (أب ثانٍ أو دورة أو فرع غير صالح)؛ لا تُستعاد.');
        }
        // A restored record never republishes itself; it returns pending review and is published by a separate decision.
        $unpublished = $this->isPublic($record, $record->getAttributes());
        if ($unpublished) {
            $record->verification_status = Source::VERIFICATION_PENDING_REVIEW;
            if (array_key_exists('public_narrative', $record->getAttributes())) {
                $record->public_narrative = false;
            }
        }

        return [
            'tree' => $tree, 'record' => $record, 'kind' => $kind, 'operation' => 'restore', 'data' => [], 'snapshot' => $snapshot,
            'current' => [], 'proposed' => array_replace($snapshot, $unpublished ? ['verification_status' => Source::VERIFICATION_PENDING_REVIEW, 'public_narrative' => false] : []),
            'referenceLabels' => $this->mutations->referenceLabels($tree, [], $snapshot),
            'publishes' => false, 'broadens' => [], 'unpublished' => $unpublished,
            'sharedFamilies' => app(SharedAncestryScope::class)->impactedFamilies($record),
            'relatedDeletions' => $kind === 'person' ? $this->relatedDeletions($log) : collect(),
            'fingerprint' => $this->fingerprint($log, $kind, (int) $snapshot['id']),
        ];
    }

    /** @param array<string, mixed> $context */
    private function restore(array $context, AuditLog $log, User $user, string $reason): AuditLog
    {
        $record = $context['record'];
        $this->publication->assertAllowed($user, $context['tree']->family, $record);
        $record->save();
        if ($record instanceof Person) {
            $attributeIds = PersonAttribute::query()->availableFor($context['tree']->family_id)->whereIn('id', (array) ($context['snapshot']['attribute_ids'] ?? []))->pluck('id')->all();
            $record->personAttributes()->sync($attributeIds);
        }
        if ($record instanceof PersonRelationship || $record instanceof Branch) {
            $this->mutations->validateBranches($context['tree']);
        }
        $new = $this->audit->record($user, $context['tree']->family, $record->fresh(), 'restored', null, $this->audit->snapshot($record->fresh()));
        $new->update(['reverted_from_id' => $log->id, 'reason' => $reason]);

        return $new;
    }

    /** @param array<string, mixed> $snapshot */
    private function restoredModel(string $kind, array $snapshot): Model
    {
        $class = $this->mutations->modelClass($kind);
        $model = new $class;
        $values = array_intersect_key($snapshot, array_flip([...$model->getFillable(), 'id', 'name_search', 'alternate_name_search']));
        foreach (['alternate_names', 'field_visibility'] as $json) {
            if (isset($values[$json]) && is_string($values[$json])) {
                $values[$json] = json_decode($values[$json], true);
            }
        }
        $model->forceFill($values);

        return $model;
    }

    /** @return array<int, string> */
    private function personRestoreBlockers(FamilyTree $tree, Person $person): array
    {
        return $person->canonical_key && $tree->people()->where('canonical_key', $person->canonical_key)->exists()
            ? ['المفتاح المرجعي '.$person->canonical_key.' مستخدم الآن لشخص آخر في الشجرة.']
            : [];
    }

    /** @return array<int, string> */
    private function relationshipRestoreBlockers(FamilyTree $tree, PersonRelationship $edge): array
    {
        $blockers = [];
        foreach (['person_id' => 'الابن', 'related_person_id' => 'الأب'] as $column => $label) {
            if (! $tree->people()->whereKey($edge->{$column})->exists()) {
                $blockers[] = $label.' (#'.$edge->{$column}.') غير موجود في الشجرة حاليًا؛ استعد الشخص أولًا.';
            }
        }
        if ($edge->canonical_key && $tree->personRelationships()->where('canonical_key', $edge->canonical_key)->exists()) {
            $blockers[] = 'المفتاح المرجعي للعلاقة مستخدم الآن.';
        }

        return $blockers;
    }

    /** @return array<int, string> */
    private function branchRestoreBlockers(FamilyTree $tree, Branch $branch): array
    {
        $blockers = [];
        if (! $tree->people()->whereKey($branch->root_person_id)->exists()) {
            $blockers[] = 'مؤسس الفرع غير موجود حاليًا؛ استعده أولًا.';
        }
        if ($branch->parent_branch_id && ! $tree->branches()->whereKey($branch->parent_branch_id)->exists()) {
            $blockers[] = 'الفرع الأب غير موجود حاليًا.';
        }
        if ($tree->branches()->where('slug', $branch->slug)->exists()) {
            $blockers[] = 'الرابط المختصر '.$branch->slug.' مستخدم الآن لفرع آخر.';
        }

        return $blockers;
    }

    /** @return array<int, string> labels of person fields whose privacy level the revert would widen */
    private function broadenedFields(Person $current, Person $candidate): array
    {
        $privacy = app(PersonPrivacy::class);
        $rank = [PersonPrivacy::PUBLIC => 0, PersonPrivacy::FAMILY => 1, PersonPrivacy::RESTRICTED => 2];
        $fields = [];
        foreach (PersonPrivacy::FIELDS as $field => $default) {
            $now = $privacy->level($field, $current->field_visibility, $current->is_living);
            $then = $privacy->level($field, $candidate->field_visibility, $candidate->is_living);
            if ($rank[$then] < $rank[$now]) {
                $fields[] = PersonPrivacy::LABELS[$field];
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $attributes */
    private function isPublic(Model $record, array $attributes): bool
    {
        return $this->publication->isPublic($record, $attributes);
    }

    private function fingerprint(AuditLog $log, string $kind, int $id): string
    {
        $class = $this->mutations->modelClass($kind);
        $query = $kind === 'person' ? Person::query()->withoutGlobalScope('active') : $class::query();
        $record = $query->find($id);

        return hash('sha256', json_encode([$log->id, AuditLog::query()->where('auditable_type', $class)->where('auditable_id', $id)->max('id'), $record?->getAttributes()], JSON_THROW_ON_ERROR));
    }
}
