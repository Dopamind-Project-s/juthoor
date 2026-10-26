<?php

namespace App\Services\Community;

use App\Models\CommunityEvent;
use App\Models\ContentNotice;
use App\Models\EntityContentLink;
use App\Models\Family;
use App\Models\NewsPost;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Changes that must stay visible after publication (v2.0 ch. 10: «تعديل الموعد أو الإلغاء يظهر بوضوح مع حفظ النسخ»):
 * updates and corrections, cancellations, event schedule changes. Each writes a public notice holding the replaced
 * values, an audit entry and in-app notifications; nothing is overwritten silently. Changing a published record needs a
 * publisher (and a family administrator for death notices and condolences, as for their publication).
 */
class CommunityPublishingService
{
    /** @var array<int, string> */
    public const EVENT_CHANGES = ['rescheduled', 'postponed', 'relocated', 'cancellation', 'reinstated', 'completed'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CommunityContentService $content,
        private readonly CommunityNotifier $notifications,
    ) {}

    public function addNotice(User $user, Model $record, string $kind, string $message): ContentNotice
    {
        abort_unless(in_array($kind, ['update', 'correction'], true), 422);

        return $this->change($user, $record, $kind, $message, fn () => []);
    }

    public function cancelNews(User $user, NewsPost $post, string $message): ContentNotice
    {
        if ($post->cancelled_at) {
            throw ValidationException::withMessages(['kind' => 'الإعلان ملغى مسبقًا.']);
        }

        return $this->change($user, $post, 'cancellation', $message, function () use ($post): array {
            $post->forceFill(['cancelled_at' => now()])->save();

            return ['cancelled_at' => null];
        });
    }

    /**
     * @param  array<string, mixed>  $data  starts_at / ends_at (event wall clock) for rescheduled/postponed; place fields for relocated
     */
    public function changeEvent(User $user, CommunityEvent $event, string $kind, array $data, string $message): ContentNotice
    {
        if (! in_array($kind, self::EVENT_CHANGES, true)) {
            throw ValidationException::withMessages(['kind' => 'نوع التغيير غير معروف.']);
        }

        return $this->change($user, $event, $kind, $message, function () use ($event, $kind, $data): array {
            $zone = $event->timezone;
            $time = fn (?string $value) => filled($value) ? CarbonImmutable::parse($value, $zone)->utc() : null;
            $previous = ['event_status' => $event->event_status, 'starts_at' => $event->starts_at?->toIso8601String(), 'ends_at' => $event->ends_at?->toIso8601String()];
            match ($kind) {
                'rescheduled' => $event->fill(['starts_at' => $time($data['starts_at'] ?? null) ?? throw ValidationException::withMessages(['starts_at' => 'حدد الموعد الجديد.']), 'ends_at' => $time($data['ends_at'] ?? null)])->forceFill(['event_status' => 'scheduled']),
                'postponed' => $event->forceFill(['event_status' => 'postponed'])->fill(filled($data['starts_at'] ?? null) ? ['starts_at' => $time($data['starts_at']), 'ends_at' => $time($data['ends_at'] ?? null)] : []),
                'relocated' => $event->fill(collect($data)->only(['location_name', 'public_address', 'city', 'governorate', 'latitude', 'longitude', 'maps_url'])->all()),
                'cancellation' => $event->forceFill(['event_status' => 'cancelled']),
                'reinstated' => $event->forceFill(['event_status' => 'scheduled']),
                'completed' => $event->forceFill(['event_status' => 'completed']),
            };
            if ($kind === 'relocated') {
                $previous = collect($event->getOriginal())->only(['location_name', 'public_address', 'city', 'governorate', 'latitude', 'longitude', 'maps_url'])->all();
            }
            if ($event->ends_at && $event->ends_at->lt($event->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => 'وقت الانتهاء يسبق وقت البدء.']);
            }
            $event->save();

            return $previous;
        });
    }

    /** A draft announcement that points at the event instead of copying it (v2.0: «توليد خبر من مناسبة دون إنشاء سجل مناسبة مكرر»). */
    public function announce(User $user, CommunityEvent $event): NewsPost
    {
        $subjects = $event->entityLinks()->where('relation', EntityContentLink::RELATION_SUBJECT)->pluck('community_entity_id')->map(fn ($id) => (int) $id)->all();
        $audiences = $event->entityLinks()->where('relation', EntityContentLink::RELATION_AUDIENCE)->pluck('community_entity_id')->map(fn ($id) => (int) $id)->all();

        return $this->content->save($user, $event->ownerEntity, new NewsPost, [
            'category' => 'announcement', 'title' => 'إعلان: '.$event->title, 'summary' => $event->organizer ? 'بتنظيم '.$event->organizer : null,
            'body' => $event->description ?: $event->title, 'event_id' => $event->id,
        ], ['publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => $event->audience, 'subjects' => $subjects, 'audiences' => $audiences]);
    }

    /** @param  callable(): array<string, mixed>  $apply returns the replaced values */
    private function change(User $user, Model $record, string $kind, string $message, callable $apply): ContentNotice
    {
        if (blank($message)) {
            throw ValidationException::withMessages(['message' => 'اكتب نص التحديث الذي سيظهر للقراء.']);
        }
        $owner = $record->ownerEntity;
        $published = in_array($record->publication_status, [$record::STATUS_PUBLISHED, $record::STATUS_ARCHIVED], true);
        abort_unless(Gate::forUser($user)->allows($published ? 'publish' : 'update', $owner), 403);
        if ($published && $record instanceof NewsPost && $record->isSensitive() && ! Gate::forUser($user)->allows('publish', $owner->family)) {
            throw ValidationException::withMessages(['kind' => 'تعديل النعي أو العزاء بعد النشر من صلاحية مدير العائلة فقط.']);
        }

        $notice = DB::transaction(function () use ($user, $record, $owner, $kind, $message, $apply): ContentNotice {
            Family::query()->whereKey($owner->family_id)->lockForUpdate()->firstOrFail();
            $before = $this->content->snapshot($record->refresh());
            $previous = $apply();
            $notice = $record->notices()->create(['kind' => $kind, 'message' => $message, 'previous' => $previous ?: null, 'created_by' => $user->id]);
            $this->audit->record($user, $owner->family, $record, 'notice_'.$kind, $before, [...$this->content->snapshot($record), 'notice_id' => $notice->id]);

            return $notice;
        });
        if ($published) {
            $this->notifications->contentChanged($user, $record, $kind, ContentNotice::KINDS[$kind]);
        }

        return $notice;
    }
}
