<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Notifications\CommunityNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/** Who hears about review requests, decisions and changes to community content (in-app only). */
class CommunityNotifier
{
    public function __construct(private readonly CommunityHierarchy $hierarchy) {}

    public function reviewRequested(User $actor, Model $record): void
    {
        $this->send($this->publishers($record->ownerEntity)->reject(fn (User $user) => $user->is($actor)), 'review_requested',
            'محتوى بانتظار مراجعتك', $this->context($record), $this->editUrl($record));
    }

    public function reviewDecided(User $actor, Model $record, string $decision, ?string $note): void
    {
        $creator = $record->creator;
        if ($creator && ! $creator->is($actor)) {
            $this->send(collect([$creator]), 'review_'.$decision, $decision === 'publish' ? 'نُشر المحتوى الذي قدمته' : 'أُعيد المحتوى الذي قدمته إلى المسودة',
                $this->context($record).($note ? ' — '.$note : ''), $this->editUrl($record));
        }
    }

    /** Published changes (schedule, cancellation, corrections) reach the author and the owner entity's publishers. */
    public function contentChanged(User $actor, Model $record, string $kind, string $label): void
    {
        $recipients = $this->publishers($record->ownerEntity)->push($record->creator)->filter()->unique('id')->reject(fn (User $user) => $user->is($actor));
        $this->send($recipients, 'changed_'.$kind, $label.': '.$record->content_title, $this->context($record), $this->editUrl($record));
    }

    /** @param  Collection<int, User>  $users */
    public function send(Collection $users, string $kind, string $title, string $body, ?string $url): void
    {
        $active = $users->filter(fn (?User $user) => $user?->is_active)->values();
        if ($active->isNotEmpty()) {
            Notification::send($active, new CommunityNotification($kind, $title, $body, $url));
        }
    }

    /**
     * Family administrators and entity administrators of the entity or an ancestor who are still family members.
     *
     * @return Collection<int, User>
     */
    public function publishers(CommunityEntity $entity): Collection
    {
        $members = FamilyMembership::query()->where('family_id', $entity->family_id);
        $entityAdmins = CommunityEntityManager::query()->where('role', CommunityEntityManager::ROLE_ADMIN)
            ->whereIn('community_entity_id', [...$this->hierarchy->ancestorIds($entity), $entity->id])->select('user_id');

        return User::query()->where('is_active', true)
            ->where(fn ($users) => $users->whereIn('id', (clone $members)->where('role', FamilyMembership::ROLE_ADMIN)->select('user_id'))
                ->orWhere(fn ($scoped) => $scoped->whereIn('id', $entityAdmins)->whereIn('id', (clone $members)->select('user_id'))))
            ->get();
    }

    private function context(Model $record): string
    {
        return (CommunityContentModules::MODULES[CommunityContentModules::keyFor($record)]['label'] ?? '').' · «'.$record->content_title.'» · '.$record->ownerEntity?->name_ar;
    }

    private function editUrl(Model $record): string
    {
        return route('management.entities.content.edit', [$record->ownerEntity->family->slug, $record->owner_entity_id, CommunityContentModules::keyFor($record), $record->id]);
    }
}
