<?php

namespace App\Services\Genealogy;

use App\Models\ArchiveRecord;
use App\Models\Assertion;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\CrossFamilyLink;
use App\Models\EntityAchievement;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\ArabicNameNormalizer;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Safe duplicate merge (JTH-10, UC-06). plan() is read-only; merge() applies an explicitly reviewed plan in one
 * transaction and writes a row-level journal; undo() replays that journal backwards only when every touched row is
 * still exactly as the merge left it. The merged person is kept (merged_into_id) and hidden by Person's global scope.
 */
class PersonMergeService
{
    /** Scalar person fields compared field by field. */
    public const FIELDS = [
        'full_name_ar' => 'الاسم الكامل', 'first_name_ar' => 'الاسم الأول', 'full_name_en' => 'الاسم بالإنجليزية', 'gender' => 'الجنس',
        'birth_year' => 'سنة الميلاد', 'birth_date' => 'تاريخ الميلاد', 'death_year' => 'سنة الوفاة', 'death_date' => 'تاريخ الوفاة',
        'is_living' => 'حالة الحياة', 'title' => 'اللقب', 'honorific' => 'عبارة التكريم', 'notes' => 'ملاحظات خاصة',
        'status' => 'حالة السجل', 'verification_status' => 'حالة التوثيق',
    ];

    /** Losing values of these fields are preserved as superseded claims (Phase 2.2). */
    private const CLAIM_FIELDS = ['full_name_ar' => 'full_name', 'birth_year' => 'birth_year', 'death_year' => 'death_year', 'title' => 'title', 'honorific' => 'honorific'];

    private const LEVEL_RANK = [PersonPrivacy::PUBLIC => 0, PersonPrivacy::FAMILY => 1, PersonPrivacy::RESTRICTED => 2];

    /** @var array<int, array<string, mixed>> */
    private array $journal = [];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GenealogyTraversalService $traversal,
        private readonly SharedAncestryScope $shared,
        private readonly PublicationGuard $publication,
        private readonly ArabicNameNormalizer $normalizer,
    ) {}

    /**
     * Read-only comparison and consequences of merging $mergedId into $survivorId.
     *
     * @param  array<string, string>  $decisions  field => survivor|merged for conflicting values
     * @return array<string, mixed>
     */
    public function plan(FamilyTree $tree, int $survivorId, int $mergedId, array $decisions = []): array
    {
        $survivor = $tree->people()->with('personAttributes')->findOrFail($survivorId);
        $merged = $tree->people()->with('personAttributes')->findOrFail($mergedId);
        $blockers = [];
        if ($survivor->is($merged)) {
            $blockers[] = 'اختر شخصين مختلفين.';
        }

        $fields = [];
        foreach (self::FIELDS as $field => $label) {
            $left = $this->normalized($survivor->getRawOriginal($field));
            $right = $this->normalized($merged->getRawOriginal($field));
            $outcome = match (true) {
                $left === $right => 'same',
                $right === null => 'keep',
                $left === null => 'fill',
                default => 'conflict',
            };
            $choice = $outcome === 'conflict' ? ($decisions[$field] ?? null) : null;
            if ($outcome === 'conflict' && ! in_array($choice, ['survivor', 'merged'], true)) {
                $blockers[] = 'اختر القيمة المعتمدة لحقل «'.$label.'»؛ القيمتان مختلفتان.';
            }
            $result = match ($outcome) {
                'fill' => $merged->getRawOriginal($field),
                'conflict' => $choice === 'merged' ? $merged->getRawOriginal($field) : $survivor->getRawOriginal($field),
                default => $survivor->getRawOriginal($field),
            };
            $fields[$field] = ['label' => $label, 'survivor' => $survivor->getRawOriginal($field), 'merged' => $merged->getRawOriginal($field), 'outcome' => $outcome, 'choice' => $choice, 'result' => $result];
        }

        [$survivorFather, $mergedFather] = [$this->fatherEdge($survivor->id), $this->fatherEdge($merged->id)];
        if ($survivorFather && $mergedFather && $survivorFather->related_person_id !== $mergedFather->related_person_id) {
            $blockers[] = 'لكل منهما أب مختلف في الشجرة المعتمدة. احسم الأبوة أولًا عبر الروايات والمراجعة؛ لا يُختار أب بالتخمين.';
        }
        if (! $survivor->is($merged)) {
            $survivorAncestors = $this->traversal->getAncestorPath($survivor->id, null, false)->modelKeys();
            $mergedAncestors = $this->traversal->getAncestorPath($merged->id, null, false)->modelKeys();
            if (in_array($merged->id, $survivorAncestors, true) || in_array($survivor->id, $mergedAncestors, true)) {
                $blockers[] = 'أحدهما من أسلاف الآخر؛ الدمج سيجعل الشخص أبًا لنفسه أو ينشئ دورة.';
            }
        }
        if ($tree->root_person_id === $merged->id && $survivorFather) {
            $blockers[] = 'السجل المدموج جذر الشجرة والسجل الباقي له أب؛ لا يصح أن يكون للجذر أب.';
        }
        if ($tree->root_person_id === $survivor->id && $mergedFather && ! $survivorFather) {
            $blockers[] = 'السجل الباقي جذر الشجرة والسجل المدموج له أب؛ نقل الأب سيجعل للجذر أبًا.';
        }
        $pending = $this->pendingChangeRequests($tree, $merged->id);
        if ($pending->isNotEmpty()) {
            $blockers[] = 'توجد مقترحات معلقة تخص السجل المدموج (#'.$pending->implode(', #').')؛ اتخذ قرارًا بشأنها أولًا.';
        }
        // Community content is not part of the merge journal; it must point at the survivor before merging.
        if (EntityAchievement::query()->where('person_id', $merged->id)->exists()) {
            $blockers[] = 'السجل المدموج مرتبط بسجلات «شخصيات وإنجازات»؛ انقلها إلى السجل الباقي من صفحات الكيانات أولًا.';
        }
        if (ArchiveRecord::query()->where('person_id', $merged->id)->exists()) {
            $blockers[] = 'السجل المدموج مرتبط بمواد أرشيفية؛ اربطها بالسجل الباقي من صفحات الكيانات أولًا.';
        }

        $resultAttributes = collect($fields)->map(fn ($field) => $field['result'])->all();
        $wasPublic = fn (Person $person) => $this->publication->isPublic($person, $person->getAttributes());
        $resultPublic = $this->publication->isPublic($survivor, $resultAttributes + $survivor->getAttributes());
        if ($resultPublic && ! $wasPublic($survivor) && ! $wasPublic($merged)) {
            $blockers[] = 'الخيارات المحددة ستنشر للعامة سجلًا لم يكن أي منهما منشورًا؛ عدّل الخيارات أو انشر السجل لاحقًا بقرار مستقل.';
        }

        $links = CrossFamilyLink::query()->where(fn ($query) => $query->whereIn('left_person_id', [$survivor->id, $merged->id])->orWhereIn('right_person_id', [$survivor->id, $merged->id]))->get();
        $impacted = $this->shared->impactedFamilies($survivor)->merge($this->shared->impactedFamilies($merged))
            ->merge($links->flatMap(fn ($link) => [$link->leftFamily, $link->rightFamily]))->filter()->unique('id')->values();

        return [
            'survivor' => $survivor, 'merged' => $merged, 'fields' => $fields, 'blockers' => $blockers,
            'fieldVisibility' => $this->stricterVisibility($survivor->field_visibility ?? [], $merged->field_visibility ?? []),
            'alternateNames' => $this->alternateNames($survivor, $merged, $fields['full_name_ar']['result']),
            'attributes' => $survivor->personAttributes->merge($merged->personAttributes)->unique('id')->pluck('label')->values()->all(),
            'survivorFather' => $survivorFather?->relatedPerson, 'mergedFather' => $mergedFather?->relatedPerson,
            'references' => $this->references($tree, $survivor, $merged, $survivorFather, $mergedFather, $links),
            'claims' => collect(self::CLAIM_FIELDS)->filter(fn ($fact, $field) => $fields[$field]['outcome'] === 'conflict')->keys()->all(),
            'publication' => ['survivor' => $wasPublic($survivor), 'merged' => $wasPublic($merged), 'result' => $resultPublic],
            'impactedFamilies' => $impacted,
            'requiresSuperAdmin' => $impacted->isNotEmpty(),
            'fingerprint' => $this->fingerprint($tree, [$survivor->id, $merged->id]),
        ];
    }

    /** @param array<string, string> $decisions */
    public function merge(FamilyTree $tree, User $user, int $survivorId, int $mergedId, array $decisions, string $fingerprint, string $reason): PersonMerge
    {
        return DB::transaction(function () use ($tree, $user, $survivorId, $mergedId, $decisions, $fingerprint, $reason): PersonMerge {
            $tree = FamilyTree::query()->lockForUpdate()->findOrFail($tree->id);
            $tree->people()->whereKey([$survivorId, $mergedId])->lockForUpdate()->get();
            $plan = $this->plan($tree, $survivorId, $mergedId, $decisions);
            $this->authorize($user, $tree, $plan['requiresSuperAdmin']);
            if (! hash_equals($plan['fingerprint'], $fingerprint)) {
                $this->reject('fingerprint', 'تغيرت بيانات أحد الشخصين أو علاقاتهما منذ المعاينة. أعد المعاينة قبل الدمج.');
            }
            if ($plan['blockers'] !== []) {
                throw ValidationException::withMessages(['merge' => $plan['blockers']]);
            }
            [$survivor, $merged] = [$plan['survivor'], $plan['merged']];
            $before = [$this->audit->snapshot($survivor), $this->audit->snapshot($merged)];
            $this->journal = [];

            $this->applySurvivorValues($plan);
            $this->preserveLosingValues($tree, $plan, $user);
            $this->moveParentage($survivor, $merged);
            $this->update('family_trees', ['root_person_id' => $merged->id], ['root_person_id' => $survivor->id]);
            $this->update('branches', ['root_person_id' => $merged->id], ['root_person_id' => $survivor->id]);
            $this->update('citations', ['person_id' => $merged->id], ['person_id' => $survivor->id]);
            $this->moveUniqueRows('person_sources', 'source_id', $survivor->id, $merged->id, 'id');
            $this->moveUniqueRows('person_attribute_assignments', 'person_attribute_id', $survivor->id, $merged->id);
            $this->update('assertions', ['person_id' => $merged->id], ['person_id' => $survivor->id]);
            $this->update('assertions', ['related_person_id' => $merged->id], ['related_person_id' => $survivor->id]);
            $this->update('cross_family_links', ['left_person_id' => $merged->id], ['left_person_id' => $survivor->id]);
            $this->update('cross_family_links', ['right_person_id' => $merged->id], ['right_person_id' => $survivor->id]);
            $this->update('people', ['id' => $merged->id], ['merged_into_id' => $survivor->id]);

            $this->validateGraph($tree->fresh(), $survivor->id);
            $operation = PersonMerge::query()->create([
                'family_tree_id' => $tree->id, 'survivor_id' => $survivor->id, 'merged_id' => $merged->id, 'performed_by' => $user->id,
                'reason' => $reason, 'fingerprint' => $fingerprint, 'decisions' => $decisions, 'journal' => $this->journal,
            ]);
            $this->recordAudit($user, $tree, $operation, 'merged', $before);

            return $operation;
        });
    }

    /** @return array<int, string> reasons the merge cannot be reversed automatically (empty when safe) */
    public function undoBlockers(PersonMerge $operation): array
    {
        if ($operation->status !== 'applied') {
            return ['تم التراجع عن هذه العملية سابقًا.'];
        }
        $blockers = [];
        foreach (array_reverse($operation->journal) as $entry) {
            $current = $this->row($entry['table'], $entry['key']);
            if ($entry['op'] === 'update') {
                foreach ($entry['to'] as $column => $value) {
                    if (! $current || $this->normalized($current[$column] ?? null) !== $this->normalized($value)) {
                        $blockers[] = 'تغير '.$this->describe($entry).' («'.$column.'») بعد الدمج.';
                    }
                }
            } elseif ($entry['op'] === 'delete' && $current) {
                $blockers[] = 'أعيد إنشاء '.$this->describe($entry).' بعد الدمج.';
            } elseif ($entry['op'] === 'insert') {
                if (! $current || array_map($this->normalized(...), $current) != array_map($this->normalized(...), $entry['row'])) {
                    $blockers[] = 'عُدّل أو حُذف '.$this->describe($entry).' الذي أنشأه الدمج.';
                } elseif ($entry['table'] === 'assertions' && DB::table('citations')->where('assertion_id', $entry['key']['id'])->exists()) {
                    $blockers[] = 'أضيف دليل إلى الرواية المحفوظة #'.$entry['key']['id'].' بعد الدمج.';
                }
            }
        }

        return array_values(array_unique($blockers));
    }

    public function undo(PersonMerge $operation, User $user, string $reason): void
    {
        DB::transaction(function () use ($operation, $user, $reason): void {
            $tree = FamilyTree::query()->lockForUpdate()->findOrFail($operation->family_tree_id);
            $operation = PersonMerge::query()->lockForUpdate()->findOrFail($operation->id);
            $this->authorize($user, $tree, $this->undoRequiresSuperAdmin($operation));
            if ($blockers = $this->undoBlockers($operation)) {
                throw ValidationException::withMessages(['undo' => $blockers]);
            }
            $before = [
                $this->audit->snapshot(Person::query()->withoutGlobalScope('active')->findOrFail($operation->survivor_id)),
                $this->audit->snapshot(Person::query()->withoutGlobalScope('active')->findOrFail($operation->merged_id)),
            ];
            foreach (array_reverse($operation->journal) as $entry) {
                match ($entry['op']) {
                    'update' => DB::table($entry['table'])->where($entry['key'])->update($entry['from']),
                    'insert' => DB::table($entry['table'])->where($entry['key'])->delete(),
                    'delete' => DB::table($entry['table'])->insert($entry['row']),
                };
            }
            $this->validateGraph($tree->fresh(), $operation->survivor_id, $operation->merged_id);
            $operation->update(['status' => 'undone', 'undone_by' => $user->id, 'undone_at' => now(), 'undo_reason' => $reason]);
            $this->recordAudit($user, $tree, $operation, 'merge_undone', $before);
        });
    }

    /** @param array<string, mixed> $plan */
    private function applySurvivorValues(array $plan): void
    {
        $survivor = $plan['survivor'];
        $values = [];
        foreach ($plan['fields'] as $field => $comparison) {
            if ($this->normalized($comparison['result']) !== $this->normalized($comparison['survivor'])) {
                $values[$field] = $comparison['result'];
            }
        }
        if (array_key_exists('is_living', $values)) {
            $values['living_status_basis'] = $plan['merged']->living_status_basis;
        }
        $values['alternate_names'] = $plan['alternateNames'] ? json_encode($plan['alternateNames']) : null;
        $values['field_visibility'] = $plan['fieldVisibility'] ? json_encode($plan['fieldVisibility']) : null;
        $values['name_search'] = $this->normalizer->normalize((string) ($values['full_name_ar'] ?? $survivor->full_name_ar));
        $variants = collect($plan['alternateNames'])->map(fn ($name) => $this->normalizer->normalize($name))->filter()->unique()->values();
        $values['alternate_name_search'] = $variants->isEmpty() ? null : '|'.$variants->implode('|').'|';
        $current = $this->row('people', ['id' => $survivor->id]);
        $values = array_filter($values, fn ($value, $column) => in_array($column, ['alternate_names', 'field_visibility'], true)
            ? json_decode((string) ($current[$column] ?? 'null'), true) != json_decode((string) ($value ?? 'null'), true)
            : $this->normalized($current[$column] ?? null) !== $this->normalized($value), ARRAY_FILTER_USE_BOTH);
        if ($values !== []) {
            $this->update('people', ['id' => $survivor->id], $values);
        }
    }

    /** @param array<string, mixed> $plan */
    private function preserveLosingValues(FamilyTree $tree, array $plan, User $user): void
    {
        foreach ($plan['claims'] as $field) {
            $comparison = $plan['fields'][$field];
            $losing = $comparison['choice'] === 'merged' ? $comparison['survivor'] : $comparison['merged'];
            $from = $comparison['choice'] === 'merged' ? $plan['survivor'] : $plan['merged'];
            $this->insert('assertions', [
                'family_tree_id' => $tree->id, 'person_id' => $plan['survivor']->id, 'fact_type' => self::CLAIM_FIELDS[$field],
                'value' => (string) $losing, 'related_person_id' => null, 'relation' => 'conflicting', 'state' => 'superseded',
                'verification_status' => $from->verification_status, 'notes' => 'قيمة السجل #'.$from->id.' قبل دمج السجلين؛ محفوظة رواية مستبدلة.',
                'import_key' => null, 'created_by' => $user->id, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_reason' => 'دمج سجلين مكررين',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function moveParentage(Person $survivor, Person $merged): void
    {
        $survivorFather = $this->fatherEdge($survivor->id);
        $mergedFather = $this->fatherEdge($merged->id);
        if ($mergedFather && $survivorFather) {
            // Same father (different fathers are a blocker): keep the survivor's edge and its evidence.
            $this->update('citations', ['person_relationship_id' => $mergedFather->id], ['person_relationship_id' => $survivorFather->id]);
            $this->delete('person_relationships', ['id' => $mergedFather->id]);
        }
        foreach (DB::table('person_relationships')->where('person_id', $merged->id)->pluck('id') as $id) {
            $this->update('person_relationships', ['id' => $id], ['person_id' => $survivor->id]);
        }
        foreach (DB::table('person_relationships')->where('related_person_id', $merged->id)->pluck('id') as $id) {
            $this->update('person_relationships', ['id' => $id], ['related_person_id' => $survivor->id]);
        }
    }

    /** Rows unique per (person, $column): consolidated when the survivor already has one, otherwise redirected. */
    private function moveUniqueRows(string $table, string $column, int $survivorId, int $mergedId, ?string $id = null): void
    {
        $existing = DB::table($table)->where('person_id', $survivorId)->pluck($column)->all();
        foreach (DB::table($table)->where('person_id', $mergedId)->get() as $row) {
            $key = $id ? [$id => $row->{$id}] : ['person_id' => $mergedId, $column => $row->{$column}];
            in_array($row->{$column}, $existing, false)
                ? $this->delete($table, $key)
                : $this->update($table, $key, ['person_id' => $survivorId]);
        }
    }

    /** @param array<string, mixed> $where @param array<string, mixed> $values */
    private function update(string $table, array $where, array $values): void
    {
        $keyColumns = $table === 'person_attribute_assignments' ? ['person_id', 'person_attribute_id'] : ['id'];
        foreach (DB::table($table)->where($where)->get() as $row) {
            $row = (array) $row;
            $key = array_intersect_key($row, array_flip($keyColumns));
            DB::table($table)->where($key)->update($values);
            $newKey = array_replace($key, array_intersect_key($values, $key));
            $after = $this->row($table, $newKey);
            $this->journal[] = ['op' => 'update', 'table' => $table, 'key' => $newKey, 'from' => array_intersect_key($row, $values), 'to' => array_intersect_key($after, $values)];
        }
    }

    /** @param array<string, mixed> $key */
    private function delete(string $table, array $key): void
    {
        $row = $this->row($table, $key);
        DB::table($table)->where($key)->delete();
        $this->journal[] = ['op' => 'delete', 'table' => $table, 'key' => $key, 'row' => $row];
    }

    /** @param array<string, mixed> $values */
    private function insert(string $table, array $values): int
    {
        $id = DB::table($table)->insertGetId($values);
        $this->journal[] = ['op' => 'insert', 'table' => $table, 'key' => ['id' => $id], 'row' => $this->row($table, ['id' => $id])];

        return $id;
    }

    /** @param array<string, mixed> $key @return array<string, mixed>|null */
    private function row(string $table, array $key): ?array
    {
        $row = DB::table($table)->where($key)->first();

        return $row ? (array) $row : null;
    }

    private function validateGraph(FamilyTree $tree, int ...$personIds): void
    {
        try {
            foreach (PersonRelationship::query()->where('family_tree_id', $tree->id)
                ->where(fn ($query) => $query->whereIn('person_id', $personIds)->orWhereIn('related_person_id', $personIds))->get() as $edge) {
                app(RelationshipIntegrityService::class)->validate($edge);
            }
            foreach ($tree->branches()->get() as $branch) {
                app(BranchIntegrityService::class)->validate($branch);
            }
            app(FamilyTreeIntegrityService::class)->validate($tree);
        } catch (DomainException $exception) {
            $this->reject('merge', 'العملية تخالف سلامة الأنساب ولم يُحفظ أي تغيير: '.$exception->getMessage());
        }
        foreach ($personIds as $id) {
            if ($person = Person::query()->find($id)) {
                app(CrossFamilyLinkInvalidator::class)->changed($person);
            }
        }
    }

    /** @param array{0: array<string, mixed>, 1: array<string, mixed>} $before */
    private function recordAudit(User $user, FamilyTree $tree, PersonMerge $operation, string $action, array $before): void
    {
        foreach ([$operation->survivor_id, $operation->merged_id] as $index => $personId) {
            $person = Person::query()->withoutGlobalScope('active')->findOrFail($personId);
            $this->audit->record($user, $tree->family, $person, $action, $before[$index], $this->audit->snapshot($person));
        }
        // The operation record holds raw values, so its audit entry is for reviewers only and carries ids, not data.
        $this->audit->record($user, $tree->family, $operation, $action, null, ['survivor_id' => $operation->survivor_id, 'merged_id' => $operation->merged_id, 'status' => $operation->status])
            ->update(['reviewers_only' => true, 'reason' => $operation->status === 'undone' ? $operation->undo_reason : $operation->reason]);
    }

    private function authorize(User $user, FamilyTree $tree, bool $requiresSuperAdmin): void
    {
        if (! $this->publication->canPublish($user, $tree->family)) {
            abort(403, 'الدمج من صلاحية مدير العائلة.');
        }
        if ($requiresSuperAdmin && ! $user->isSuperAdmin()) {
            $this->reject('merge', 'الدمج يمس جدًا مشتركًا أو رابطًا بين العائلات؛ ينفذه مشرف المنصة بعد مراجعة العائلات المتأثرة.');
        }
    }

    private function undoRequiresSuperAdmin(PersonMerge $operation): bool
    {
        return collect($operation->journal)->contains(fn ($entry) => $entry['table'] === 'cross_family_links')
            || $this->shared->impactedFamilies(Person::query()->withoutGlobalScope('active')->findOrFail($operation->survivor_id))->isNotEmpty();
    }

    private function fatherEdge(int $personId): ?PersonRelationship
    {
        return PersonRelationship::query()->with('relatedPerson')->where('person_id', $personId)->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true)->first();
    }

    /** @return Collection<int, int> */
    private function pendingChangeRequests(FamilyTree $tree, int $personId): Collection
    {
        return ChangeRequest::query()->where('family_tree_id', $tree->id)->where('status', 'pending')->get()
            ->filter(fn (ChangeRequest $change) => ($change->target_type === 'person' && (int) $change->target_id === $personId)
                || in_array($personId, array_map('intval', array_filter([$change->payload['person_id'] ?? null, $change->payload['father_id'] ?? null, $change->payload['related_person_id'] ?? null, $change->payload['root_person_id'] ?? null])), true))
            ->pluck('id')->values();
    }

    /**
     * @return array<int, array{label: string, count: int, action: string}>
     */
    private function references(FamilyTree $tree, Person $survivor, Person $merged, ?PersonRelationship $survivorFather, ?PersonRelationship $mergedFather, Collection $links): array
    {
        $count = fn (string $table, array $where) => DB::table($table)->where($where)->count();
        $sharedSources = DB::table('person_sources')->where('person_id', $merged->id)->whereIn('source_id', DB::table('person_sources')->where('person_id', $survivor->id)->pluck('source_id'))->count();

        return array_values(array_filter([
            ['label' => 'أبناء السجل المدموج', 'count' => $count('person_relationships', ['related_person_id' => $merged->id]), 'action' => 'تُنقل أبوتهم إلى السجل الباقي'],
            ['label' => 'علاقة أب السجل المدموج', 'count' => $mergedFather ? 1 : 0, 'action' => $survivorFather ? 'الأب نفسه: تُدمج في علاقة السجل الباقي وتنتقل أدلتها' : 'تنتقل إلى السجل الباقي'],
            ['label' => 'جذر الشجرة', 'count' => $tree->root_person_id === $merged->id ? 1 : 0, 'action' => 'يصبح السجل الباقي جذر الشجرة'],
            ['label' => 'فروع يؤسسها السجل المدموج', 'count' => Branch::query()->where('root_person_id', $merged->id)->count(), 'action' => 'يصبح السجل الباقي مؤسسها'],
            ['label' => 'استشهادات', 'count' => $count('citations', ['person_id' => $merged->id]), 'action' => 'تنتقل إلى السجل الباقي'],
            ['label' => 'روابط مصادر', 'count' => $count('person_sources', ['person_id' => $merged->id]), 'action' => $sharedSources ? $sharedSources.' مكررة تُوحّد والباقي ينتقل' : 'تنتقل'],
            ['label' => 'صفات وشارات', 'count' => $count('person_attribute_assignments', ['person_id' => $merged->id]), 'action' => 'تُجمع في السجل الباقي'],
            ['label' => 'روايات ووقائع', 'count' => Assertion::query()->where('person_id', $merged->id)->orWhere('related_person_id', $merged->id)->count(), 'action' => 'تنتقل كما هي بحالاتها'],
            ['label' => 'روابط بين العائلات', 'count' => $links->filter(fn ($link) => in_array($merged->id, [$link->left_person_id, $link->right_person_id], true))->count(), 'action' => 'تُوجَّه إلى السجل الباقي وتعاد مراجعتها'],
        ], fn ($row) => $row['count'] > 0));
    }

    /** @param array<int, int> $personIds */
    private function fingerprint(FamilyTree $tree, array $personIds): string
    {
        $rows = fn (string $table, string ...$columns) => DB::table($table)->where(fn ($query) => collect($columns)->each(fn ($column) => $query->orWhereIn($column, $personIds)))
            ->orderBy($table === 'person_attribute_assignments' ? 'person_attribute_id' : 'id')->get()->map(fn ($row) => array_map($this->normalized(...), (array) $row))->all();

        return hash('sha256', json_encode([
            $rows('people', 'id'), $rows('person_relationships', 'person_id', 'related_person_id'), $rows('branches', 'root_person_id'),
            $rows('citations', 'person_id'), $rows('person_sources', 'person_id'), $rows('person_attribute_assignments', 'person_id'),
            $rows('assertions', 'person_id', 'related_person_id'), $rows('cross_family_links', 'left_person_id', 'right_person_id'),
            $this->normalized(DB::table('family_trees')->where('id', $tree->id)->value('root_person_id')),
            ChangeRequest::query()->where('family_tree_id', $tree->id)->where('status', 'pending')->pluck('id')->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    private function stricterVisibility(array $left, array $right): array
    {
        $result = [];
        foreach (PersonPrivacy::FIELDS as $field => $default) {
            $levels = array_filter([$left[$field] ?? null, $right[$field] ?? null]);
            if ($levels !== []) {
                $result[$field] = collect($levels)->sortByDesc(fn ($level) => self::LEVEL_RANK[$level] ?? 2)->first();
            }
        }

        return $result;
    }

    /** @return array<int, string> */
    private function alternateNames(Person $survivor, Person $merged, ?string $displayName): array
    {
        return collect([...($survivor->alternate_names ?? []), ...($merged->alternate_names ?? []), $survivor->full_name_ar, $merged->full_name_ar])
            ->filter(fn ($name) => $name && $name !== $displayName)->unique()->values()->all();
    }

    private function normalized(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => (string) (int) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }

    /** @param array<string, mixed> $entry */
    private function describe(array $entry): string
    {
        $labels = ['people' => 'سجل الشخص', 'person_relationships' => 'علاقة الأبوة', 'branches' => 'الفرع', 'citations' => 'الاستشهاد', 'person_sources' => 'رابط المصدر',
            'person_attribute_assignments' => 'إسناد الصفة', 'assertions' => 'الرواية', 'cross_family_links' => 'الرابط بين العائلات', 'family_trees' => 'جذر الشجرة'];

        return ($labels[$entry['table']] ?? $entry['table']).' #'.implode('/', $entry['key']);
    }

    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
