<?php

namespace App\Services\Genealogy;

use App\Models\Assertion;
use App\Models\ChangeRequest;
use App\Models\Citation;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ChangeReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of genealogical assertions (chapter 14). Recording or classifying a claim never touches the active
 * genealogy. Making a claim authoritative goes through an ordinary change request, so review, integrity checks,
 * publication and shared-ancestor guards and the audit trail are the existing ones.
 */
class GenealogyAssertionService
{
    public function __construct(private readonly AuditLogger $audit, private readonly PublicationGuard $publication, private readonly GenealogyTraversalService $traversal) {}

    /** @param array<string, mixed> $input */
    public function record(FamilyTree $tree, User $user, Person $person, array $input): Assertion
    {
        Gate::forUser($user)->authorize('propose', $tree->family);
        $data = Validator::make($input, [
            'fact_type' => ['required', Rule::in(array_keys(Assertion::FACTS))],
            'value' => ['exclude_if:fact_type,father', 'required', 'string', 'max:255'],
            'related_person_id' => ['exclude_unless:fact_type,father', 'required', 'integer'],
            'relation' => ['nullable', Rule::in(array_keys(Assertion::RELATIONS))],
            'verification_status' => ['nullable', Rule::in(['pending_review', 'sourced', 'verified', 'unresolved'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['value.required' => 'أدخل القيمة المروية.', 'related_person_id.required' => 'اختر الأب المروي من هذه الشجرة.'])->validate();
        if (in_array($data['fact_type'], ['birth_year', 'death_year'], true)) {
            Validator::make($data, ['value' => ['integer', 'between:1,9999']], ['value.integer' => 'السنة يجب أن تكون رقمًا.'])->validate();
        }
        $data['verification_status'] = $this->allowedVerification($tree, $user, $data['verification_status'] ?? 'pending_review');
        $data['relation'] = $data['relation'] ?? ($data['fact_type'] === 'alternate_name' ? 'alternate_spelling' : 'unclassified');

        return DB::transaction(function () use ($tree, $user, $person, $data): Assertion {
            FamilyTree::query()->whereKey($tree->id)->lockForUpdate()->firstOrFail();
            $person = $tree->people()->findOrFail($person->id);
            if ($data['fact_type'] === 'father') {
                $this->assertValidFatherClaim($tree, $person, (int) $data['related_person_id']);
            }
            $value = $data['fact_type'] === 'father' ? null : trim((string) $data['value']);
            if ($data['fact_type'] === 'alternate_name' && $value === $person->full_name_ar) {
                $this->reject('value', 'هذه هي التهجئة المعروضة حاليًا.');
            }
            $duplicate = $person->assertions()->where('fact_type', $data['fact_type'])->where('state', '!=', 'rejected')
                ->when($value !== null, fn ($query) => $query->where('value', $value), fn ($query) => $query->where('related_person_id', $data['related_person_id']))
                ->exists();
            if ($duplicate) {
                $this->reject('value', 'هذه الرواية مسجلة لهذا الشخص بالفعل؛ أضف إليها دليلًا بدل تكرارها.');
            }
            $assertion = $person->assertions()->create([
                'family_tree_id' => $tree->id, 'fact_type' => $data['fact_type'], 'value' => $value,
                'related_person_id' => $data['fact_type'] === 'father' ? (int) $data['related_person_id'] : null,
                'relation' => $data['relation'], 'state' => 'proposed', 'verification_status' => $data['verification_status'],
                'notes' => $data['notes'] ?? null, 'created_by' => $user->id,
            ]);
            $this->audit->record($user, $tree->family, $assertion, 'created', null, $this->audit->snapshot($assertion));

            return $assertion;
        });
    }

    /** @param array{relation?: string, verification_status?: string} $input */
    public function classify(Assertion $assertion, User $user, array $input): void
    {
        $tree = $assertion->familyTree;
        Gate::forUser($user)->authorize('manageSources', $tree->family);
        $data = Validator::make($input, [
            'relation' => ['required', Rule::in(array_keys(Assertion::RELATIONS))],
            'verification_status' => ['required', Rule::in(['pending_review', 'sourced', 'verified', 'unresolved'])],
        ])->validate();
        if (in_array($assertion->state, ['rejected', 'superseded'], true)) {
            $this->reject('assertion', 'الرواية المرفوضة أو المستبدلة محفوظة كما هي؛ سجّل رواية جديدة عند الحاجة.');
        }
        $data['verification_status'] = $this->allowedVerification($tree, $user, $data['verification_status']);
        DB::transaction(function () use ($assertion, $user, $data, $tree): void {
            $locked = Assertion::query()->lockForUpdate()->findOrFail($assertion->id);
            $before = $this->audit->snapshot($locked);
            $locked->update($data);
            $this->audit->record($user, $tree->family, $locked, 'updated', $before, $this->audit->snapshot($locked));
        });
    }

    /**
     * Evidence for one claim, reusing the family source library (one source may support many claims).
     *
     * @param  array{locator?: ?string, url?: ?string, notes?: ?string, verification_status: string}  $data
     */
    public function cite(Assertion $assertion, User $user, Source $source, array $data): Citation
    {
        $tree = $assertion->familyTree;
        Gate::forUser($user)->authorize('manageSources', $tree->family);
        abort_unless($source->family_id === $tree->family_id, 404);
        if ($source->citations()->whereNotNull('cross_family_link_id')->exists()) {
            Gate::forUser($user)->authorize('manageMembers', $tree->family);
        }
        $data['verification_status'] = $this->allowedVerification($tree, $user, $data['verification_status']);

        return DB::transaction(function () use ($assertion, $user, $source, $data, $tree): Citation {
            Source::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($assertion->citations()->where('source_id', $source->id)->where('locator', $data['locator'] ?? null)->exists()) {
                $this->reject('locator', 'هذا الموضع من المصدر مستشهد به لهذه الرواية بالفعل.');
            }
            $citation = $assertion->citations()->create($data + ['source_id' => $source->id, 'family_tree_id' => $tree->id, 'field_key' => 'assertion']);
            $this->audit->record($user, $tree->family, $citation, 'created', null, $this->audit->snapshot($citation));

            return $citation;
        });
    }

    /** Submits a change request that would make the claim the displayed value (or an accepted variant). */
    public function propose(Assertion $assertion, User $user, string $reason): ChangeRequest
    {
        $tree = $assertion->familyTree;
        Gate::forUser($user)->authorize('propose', $tree->family);
        if ($assertion->state !== 'proposed') {
            $this->reject('assertion', 'يمكن اقتراح اعتماد الروايات المسجلة غير المعتمدة فقط.');
        }
        if ($assertion->changeRequests()->where('status', 'pending')->exists()) {
            $this->reject('assertion', 'لهذه الرواية مقترح اعتماد قيد المراجعة بالفعل.');
        }
        $person = $assertion->person;
        if ($assertion->fact_type === 'father') {
            $edge = $person->outgoingRelationships()->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true)->first();
            [$kind, $operation, $targetId, $payload] = $edge
                ? ['relationship', 'update', $edge->id, ['person_id' => $person->id, 'father_id' => $assertion->related_person_id, 'verification_status' => $edge->verification_status]]
                : ['relationship', 'create', null, ['person_id' => $person->id, 'father_id' => $assertion->related_person_id, 'verification_status' => Source::VERIFICATION_PENDING_REVIEW]];
        } else {
            $field = Assertion::FACTS[$assertion->fact_type]['field'];
            $value = $assertion->fact_type === 'alternate_name'
                ? array_values(array_unique([...($person->alternate_names ?? []), $assertion->value]))
                : $assertion->value;
            [$kind, $operation, $targetId, $payload] = ['person', 'update', $person->id, [$field => $value]];
        }
        $change = app(ChangeReviewService::class)->submit($tree, $user, $kind, $operation, $targetId, $payload, $reason);
        $change->update(['assertion_id' => $assertion->id]);

        return $change;
    }

    /** Called inside ChangeReviewService::decide after an accepted change was applied. */
    public function accepted(ChangeRequest $change, User $user, string $reason): void
    {
        $assertion = Assertion::query()->lockForUpdate()->findOrFail($change->assertion_id);
        $before = $this->audit->snapshot($assertion);
        if ($assertion->fact_type === 'alternate_name' || $assertion->relation === 'alternate_spelling') {
            $state = 'alternative';
        } else {
            $state = 'authoritative';
            $previous = Assertion::query()->where('person_id', $assertion->person_id)->where('fact_type', $assertion->fact_type)
                ->where('state', 'authoritative')->whereKeyNot($assertion->id)->lockForUpdate()->get();
            foreach ($previous as $old) {
                $this->transition($old, $user, 'superseded', 'استُبدلت باعتماد الرواية #'.$assertion->id);
            }
            if ($previous->isEmpty()) {
                $this->preserveReplacedValue($change, $assertion, $user);
            }
        }
        $assertion->update(['state' => $state, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_reason' => $reason]);
        $this->audit->record($user, $assertion->familyTree->family, $assertion, 'accepted', $before, $this->audit->snapshot($assertion));
    }

    public function rejected(ChangeRequest $change, User $user, string $reason): void
    {
        $assertion = Assertion::query()->lockForUpdate()->findOrFail($change->assertion_id);
        $this->transition($assertion, $user, 'rejected', $reason);
    }

    /** The value shown before the claim was accepted becomes a superseded claim, so no account is lost. */
    private function preserveReplacedValue(ChangeRequest $change, Assertion $assertion, User $user): void
    {
        $base = $change->base ?? [];
        $value = $assertion->fact_type === 'father' ? null : ($base[Assertion::FACTS[$assertion->fact_type]['field']] ?? null);
        $related = $assertion->fact_type === 'father' ? ($base['related_person_id'] ?? null) : null;
        if ($value === null && $related === null) {
            return;
        }
        $previous = Assertion::query()->create([
            'family_tree_id' => $assertion->family_tree_id, 'person_id' => $assertion->person_id, 'fact_type' => $assertion->fact_type,
            'value' => $value === null ? null : (string) $value, 'related_person_id' => $related, 'relation' => 'unclassified', 'state' => 'superseded',
            'verification_status' => $base['verification_status'] ?? Source::VERIFICATION_PENDING_REVIEW,
            'notes' => 'القيمة المعروضة قبل اعتماد الرواية #'.$assertion->id.' (محفوظة تلقائيًا).',
            'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_reason' => 'استُبدلت باعتماد الرواية #'.$assertion->id,
        ]);
        $this->audit->record($user, $assertion->familyTree->family, $previous, 'created', null, $this->audit->snapshot($previous));
    }

    private function transition(Assertion $assertion, User $user, string $state, string $reason): void
    {
        $before = $this->audit->snapshot($assertion);
        $assertion->update(['state' => $state, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_reason' => $reason]);
        $this->audit->record($user, $assertion->familyTree->family, $assertion, $state, $before, $this->audit->snapshot($assertion));
    }

    private function assertValidFatherClaim(FamilyTree $tree, Person $person, int $fatherId): void
    {
        $tree->people()->findOrFail($fatherId);
        if ($fatherId === $person->id) {
            $this->reject('related_person_id', 'لا يمكن أن يكون الشخص أبًا لنفسه.');
        }
        if (in_array($fatherId, $this->traversal->getDescendantIds($person->id, null, false), true)) {
            $this->reject('related_person_id', 'هذا الشخص من ذرية صاحب السجل؛ اعتماده أبًا ينشئ دورة في النسب.');
        }
    }

    /** Readers record claims as pending; only family administrators may mark a claim verified. */
    private function allowedVerification(FamilyTree $tree, User $user, string $status): string
    {
        if ($status === Source::VERIFICATION_VERIFIED && ! $this->publication->canPublish($user, $tree->family)) {
            $this->reject('verification_status', 'وصف الرواية بأنها «موثقة» من صلاحية مدير العائلة.');
        }
        if ($status !== Source::VERIFICATION_PENDING_REVIEW && ! Gate::forUser($user)->allows('manageSources', $tree->family)) {
            return Source::VERIFICATION_PENDING_REVIEW;
        }

        return $status;
    }

    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
