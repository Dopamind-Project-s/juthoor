<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use App\Models\ContentCitation;
use App\Models\Diwan;
use App\Models\EntityContentLink;
use App\Models\Family;
use App\Models\MediaItem;
use App\Models\Source;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for community content. Rights come from the owner entity (CommunityEntityPolicy): managers
 * of the owner draft and submit; publishers (family or entity administrators) publish, archive, verify and change
 * published records. Every linked subject or audience entity must be one the actor manages, so nobody places content
 * on pages outside their scope. Writes lock the family row and are audited with links and evidence.
 */
class CommunityContentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntityContentScope $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  module fields (validated by the module's contentRules)
     * @param  array{publication_status: string, verification_status: string, audience: string, subjects: array<int, int>, audiences: array<int, int>}  $governance
     */
    public function save(User $user, CommunityEntity $owner, Model $record, array $fields, array $governance): Model
    {
        $this->authorize($user, 'update', $owner);
        $subjects = $governance['subjects'] === [] ? [$owner->id] : $governance['subjects'];
        $linked = CommunityEntity::query()->whereIn('id', [...$subjects, ...$governance['audiences']])->get();
        foreach ($linked as $entity) {
            if ((int) $entity->family_id !== (int) $owner->family_id || ! Gate::forUser($user)->allows('update', $entity)) {
                throw ValidationException::withMessages(['subjects' => 'يمكن ربط المحتوى بالكيانات التي تديرها في العائلة نفسها فقط.']);
            }
        }

        return DB::transaction(function () use ($user, $owner, $record, $fields, $governance, $subjects): Model {
            Family::query()->whereKey($owner->family_id)->lockForUpdate()->firstOrFail();
            $before = $record->exists ? $this->snapshot($record->refresh()) : null;
            $mayPublish = Gate::forUser($user)->allows('publish', $owner);
            if (! $mayPublish && $record->exists && in_array($record->publication_status, [$record::STATUS_PUBLISHED, $record::STATUS_ARCHIVED], true)) {
                throw ValidationException::withMessages(['publication_status' => 'هذا السجل منشور أو مؤرشف؛ تعديله من صلاحية مدير العائلة أو مدير الكيان.']);
            }
            $status = $governance['publication_status'];
            if (! $mayPublish && ! in_array($status, [$record::STATUS_DRAFT, $record::STATUS_SUBMITTED], true)) {
                throw ValidationException::withMessages(['publication_status' => 'النشر والأرشفة من صلاحية مدير العائلة أو مدير الكيان؛ احفظ السجل مسودة أو اطلب مراجعته.']);
            }
            $verification = $governance['verification_status'];
            if (! $mayPublish && in_array($verification, ContentCitation::PUBLIC_STATUSES, true) && $verification !== $record->getOriginal('verification_status')) {
                throw ValidationException::withMessages(['verification_status' => 'اعتماد حالة «موثق» أو «مسند» من صلاحية مدير العائلة أو مدير الكيان.']);
            }

            $record->fill([...$fields, 'publication_status' => $status, 'verification_status' => $verification, 'audience' => $governance['audience']]);
            if (! $record->exists) {
                $record->fill(['owner_entity_id' => $owner->id, 'created_by' => $user->id]);
            }
            if ($status === $record::STATUS_SUBMITTED && $record->getOriginal('publication_status') !== $record::STATUS_SUBMITTED) {
                $record->submitted_at = now();
            }
            // Re-checked on every save of a published record, so a later audience or rights change cannot slip through.
            if ($status === $record::STATUS_PUBLISHED) {
                $this->assertPublishable($record);
            }
            if ($status === $record::STATUS_PUBLISHED && $record->getOriginal('publication_status') !== $record::STATUS_PUBLISHED) {
                $record->fill(['reviewed_at' => now(), 'reviewed_by' => $user->id]);
            }
            $record->save();
            $this->syncLinks($record, $subjects, $governance['audiences']);
            $this->audit->record($user, $owner->family, $record, $before ? 'updated' : 'created', $before, $this->snapshot($record));

            return $record;
        });
    }

    /** An editor hands a draft to the publishers. */
    public function submit(User $user, Model $record): void
    {
        $owner = $record->ownerEntity;
        $this->authorize($user, 'update', $owner);
        $this->transition($user, $record, [$record::STATUS_DRAFT], 'submitted', fn () => $record->fill(['publication_status' => $record::STATUS_SUBMITTED, 'submitted_at' => now()]));
    }

    /** Publishers publish, decline (back to draft with a reason), archive, or restore an archived record as a draft. */
    public function review(User $user, Model $record, string $decision, ?string $note): void
    {
        $this->authorize($user, 'publish', $record->ownerEntity);
        [$from, $apply] = match ($decision) {
            'publish' => [[$record::STATUS_DRAFT, $record::STATUS_SUBMITTED], function () use ($record, $user, $note): void {
                $this->assertPublishable($record);
                $record->fill(['publication_status' => $record::STATUS_PUBLISHED, 'reviewed_at' => now(), 'reviewed_by' => $user->id, 'review_note' => $note]);
            }],
            'decline' => [[$record::STATUS_SUBMITTED], function () use ($record, $user, $note): void {
                if (blank($note)) {
                    throw ValidationException::withMessages(['review_note' => 'اذكر سبب إعادة السجل إلى المسودة.']);
                }
                $record->fill(['publication_status' => $record::STATUS_DRAFT, 'reviewed_at' => now(), 'reviewed_by' => $user->id, 'review_note' => $note]);
            }],
            'archive' => [[$record::STATUS_DRAFT, $record::STATUS_SUBMITTED, $record::STATUS_PUBLISHED], fn () => $record->fill(['publication_status' => $record::STATUS_ARCHIVED, 'review_note' => $note])],
            'restore' => [[$record::STATUS_ARCHIVED], fn () => $record->fill(['publication_status' => $record::STATUS_DRAFT, 'review_note' => $note])],
            default => throw ValidationException::withMessages(['decision' => 'قرار غير معروف.']),
        };
        $this->transition($user, $record, $from, 'review_'.$decision, $apply);
    }

    /** @param  array{source_id: int, locator?: ?string, url?: ?string, notes?: ?string, verification_status: string}  $data */
    public function cite(User $user, Model $record, array $data): ContentCitation
    {
        $owner = $record->ownerEntity;
        $this->authorize($user, 'update', $owner);
        // Sources evidencing cross-family links are reviewer-only (cf. SourceController), so they never back public content.
        if (! Source::query()->whereKey($data['source_id'])->where('family_id', $owner->family_id)
            ->whereDoesntHave('citations', fn ($citations) => $citations->whereNotNull('cross_family_link_id'))->exists()) {
            throw ValidationException::withMessages(['source_id' => 'المصدر يجب أن يكون من مصادر العائلة نفسها وغير مخصص لمطابقات العائلات.']);
        }
        if (in_array($data['verification_status'], ContentCitation::PUBLIC_STATUSES, true) && ! Gate::forUser($user)->allows('publish', $owner)) {
            throw ValidationException::withMessages(['verification_status' => 'اعتماد الاستشهاد من صلاحية مدير العائلة أو مدير الكيان.']);
        }

        return DB::transaction(function () use ($user, $record, $owner, $data): ContentCitation {
            $citation = $record->citations()->create([...$data, 'created_by' => $user->id]);
            $this->audit->record($user, $owner->family, $citation, 'created', null, $this->audit->snapshot($citation));

            return $citation;
        });
    }

    public function reviewCitation(User $user, ContentCitation $citation, string $status): void
    {
        $owner = $citation->citable->ownerEntity;
        $this->authorize($user, 'publish', $owner);
        DB::transaction(function () use ($user, $citation, $owner, $status): void {
            $before = $this->audit->snapshot($citation);
            $citation->update(['verification_status' => $status]);
            $this->audit->record($user, $owner->family, $citation, 'updated', $before, $this->audit->snapshot($citation));
        });
    }

    /** Managers remove unapproved evidence; approved evidence is removed only by publishers. */
    public function removeCitation(User $user, ContentCitation $citation): void
    {
        $owner = $citation->citable->ownerEntity;
        $this->authorize($user, in_array($citation->verification_status, ContentCitation::PUBLIC_STATUSES, true) ? 'publish' : 'update', $owner);
        DB::transaction(function () use ($user, $citation, $owner): void {
            $before = $this->audit->snapshot($citation);
            $citation->delete();
            $this->audit->record($user, $owner->family, $citation, 'deleted', $before, null);
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(Model $record): array
    {
        $links = $record->entityLinks()->orderBy('id')->get(['community_entity_id', 'relation']);

        return [
            ...$this->audit->snapshot($record),
            'subject_ids' => $links->where('relation', EntityContentLink::RELATION_SUBJECT)->pluck('community_entity_id')->map(fn ($id) => (int) $id)->values()->all(),
            'audience_ids' => $links->where('relation', EntityContentLink::RELATION_AUDIENCE)->pluck('community_entity_id')->map(fn ($id) => (int) $id)->values()->all(),
            'citation_ids' => $record->citations()->orderBy('id')->pluck('id')->all(),
        ];
    }

    /**
     * @param  array<int, int>  $subjects
     * @param  array<int, int>  $audiences
     */
    private function syncLinks(Model $record, array $subjects, array $audiences): void
    {
        $wanted = collect($subjects)->map(fn ($id) => [EntityContentLink::RELATION_SUBJECT, (int) $id])
            ->merge(collect($audiences)->map(fn ($id) => [EntityContentLink::RELATION_AUDIENCE, (int) $id]))->unique(fn ($pair) => implode(':', $pair));
        $record->entityLinks()->get()->each(function (EntityContentLink $link) use ($wanted): void {
            if (! $wanted->contains(fn ($pair) => $pair === [$link->relation, (int) $link->community_entity_id])) {
                $link->delete();
            }
        });
        $entities = CommunityEntity::query()->whereIn('id', $wanted->pluck(1))->get()->keyBy('id');
        $wanted->each(fn ($pair) => $this->scope->link($record, $entities[$pair[1]], $pair[0]));
    }

    /**
     * Publication preconditions: a private residence needs recorded consent; heritage material needs usage rights that
     * allow its audience (no public copyrighted material without permission); published images need alternative text.
     */
    private function assertPublishable(Model $record): void
    {
        if ($record instanceof Diwan && $record->is_private_residence && ! $record->residence_consent) {
            throw ValidationException::withMessages(['residence_consent' => 'لا يُنشر منزل خاص بوصفه ديوانًا إلا بموافقة مسجلة من صاحبه.']);
        }
        if (method_exists($record, 'rightsAllowAudience') && ! $record->rightsAllowAudience($record->audience)) {
            throw ValidationException::withMessages(['rights_status' => 'حقوق الاستخدام لا تسمح بهذا الجمهور: النشر للعامة يتطلب حقوقًا واضحة (ملكية أو إذن أو رخصة أو ملك عام)، والمواد غير المسموح بنشرها تبقى مقيدة.']);
        }
        if ($record instanceof MediaItem && $record->isImage() && blank($record->alt_text)) {
            throw ValidationException::withMessages(['alt_text' => 'أضف نصًا بديلًا يصف الصورة قبل نشرها.']);
        }
    }

    /** @param  array<int, string>  $from */
    private function transition(User $user, Model $record, array $from, string $action, callable $apply): void
    {
        DB::transaction(function () use ($user, $record, $from, $action, $apply): void {
            Family::query()->whereKey($record->ownerEntity->family_id)->lockForUpdate()->firstOrFail();
            $record->refresh();
            if (! in_array($record->publication_status, $from, true)) {
                throw ValidationException::withMessages(['publication_status' => 'لا يمكن تنفيذ هذا الإجراء على سجل بحالة «'.$record->status_label.'».']);
            }
            $before = $this->snapshot($record);
            $apply();
            $record->save();
            $this->audit->record($user, $record->ownerEntity->family, $record, $action, $before, $this->snapshot($record));
        });
    }

    private function authorize(User $user, string $ability, ?CommunityEntity $entity): void
    {
        if (! $entity || ! Gate::forUser($user)->allows($ability, $entity)) {
            abort(403);
        }
    }
}
