<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use App\Models\DirectoryReport;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\MemberProfile;
use App\Models\User;
use App\Services\ArabicNameNormalizer;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The voluntary member directory (v2.0 ch. 12). Only the member writes their profile; family administrators verify the
 * claimed affiliation, handle reports and may suspend a listing, but never edit or publish a member's data. Search runs
 * on text precomputed per audience from the fields that audience may read, so hidden fields can never be matched,
 * filtered on or returned. Contact happens through in-app requests that reveal no address.
 */
class MemberDirectory
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CommunityHierarchy $hierarchy,
        private readonly CommunityNotifier $notifier,
        private readonly MediaStorage $storage,
        private readonly ArabicNameNormalizer $normalizer,
    ) {}

    public function canHaveProfile(User $user, Family $family): bool
    {
        return $user->is_active && $user->hasVerifiedEmail() && $family->is_active && $user->roleFor($family) !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $visibility
     */
    public function save(User $user, Family $family, array $data, array $visibility, bool $listed, ?UploadedFile $photo, bool $removePhoto): MemberProfile
    {
        abort_unless($this->canHaveProfile($user, $family), 403);
        if (($data['entity_id'] ?? null) && ! CommunityEntity::query()->where('family_id', $family->id)->whereKey($data['entity_id'])->exists()) {
            throw ValidationException::withMessages(['entity_id' => 'الكيان ليس من هذه العائلة.']);
        }
        $stored = null;
        if ($photo) {
            $inspected = $this->storage->inspect($photo);
            if ($inspected['category'] !== 'image') {
                throw ValidationException::withMessages(['photo' => 'الصورة يجب أن تكون JPEG أو PNG.']);
            }
            $stored = $this->storage->store($family, $photo, $inspected);
        }

        try {
            return DB::transaction(function () use ($user, $family, $data, $visibility, $listed, $stored, $removePhoto): MemberProfile {
                $profile = MemberProfile::query()->where('user_id', $user->id)->where('family_id', $family->id)->lockForUpdate()->first()
                    ?? (new MemberProfile)->forceFill(['user_id' => $user->id, 'family_id' => $family->id]);
                $before = $profile->exists ? $this->audit->snapshot($profile) : null;
                $oldPhoto = [$profile->photo_disk, $profile->photo_path];
                $entityChanged = (int) ($data['entity_id'] ?? 0) !== (int) $profile->entity_id;
                $profile->fill([...$data, 'field_visibility' => collect(MemberProfile::FIELDS)->map(fn ($field, $key) => $visibility[$key] ?? $field[1])->all()]);
                if ($entityChanged) {
                    // A new claim waits for a family administrator; only verified affiliations are shown and filterable.
                    $profile->forceFill(['affiliation_status' => $profile->entity_id ? 'pending' : 'unverified', 'affiliation_reviewed_by' => null, 'affiliation_reviewed_at' => null, 'affiliation_note' => null]);
                }
                if ($stored) {
                    $profile->forceFill(['photo_disk' => $stored['disk'], 'photo_path' => $stored['display_path']]);
                } elseif ($removePhoto) {
                    $profile->forceFill(['photo_disk' => null, 'photo_path' => null]);
                }
                $profile->forceFill(['is_listed' => $listed, 'listed_at' => $listed ? ($profile->listed_at ?? now()) : null]);
                $this->index($profile);
                $profile->save();
                $this->audit->record($user, $family, $profile, $before ? 'updated' : 'created', $before, $this->audit->snapshot($profile));
                if (($stored || $removePhoto) && $oldPhoto[1]) {
                    DB::afterCommit(fn () => Storage::disk($oldPhoto[0])->delete($oldPhoto[1]));
                }
                if ($stored) {
                    // Only the metadata-free display copy is kept for a profile photo.
                    DB::afterCommit(fn () => Storage::disk($stored['disk'])->delete($stored['path']));
                }
                if ($entityChanged && $profile->entity_id) {
                    DB::afterCommit(fn () => $this->notifier->send($this->admins($family), 'directory_affiliation', 'طلب توثيق انتماء في الدليل', $profile->display_name, route('management.directory.review', $family->slug)));
                }

                return $profile;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                $this->storage->delete($stored['disk'], [$stored['path'], $stored['display_path']]);
            }
            throw $exception;
        }
    }

    /** Removes the directory profile and its photo; the user's account and any genealogy record are untouched. */
    public function delete(User $user, MemberProfile $profile): void
    {
        abort_unless($profile->user_id === $user->id, 403);
        DB::transaction(function () use ($user, $profile): void {
            $before = $this->audit->snapshot($profile);
            $photo = [$profile->photo_disk, $profile->photo_path];
            $profile->delete();
            $this->audit->record($user, $profile->family, $profile, 'deleted', $before, null);
            if ($photo[1]) {
                DB::afterCommit(fn () => Storage::disk($photo[0])->delete($photo[1]));
            }
        });
    }

    /** 'members' for members of the family (and the super admin), otherwise 'public'. */
    public function viewerLevel(?User $viewer, Family $family): string
    {
        return $viewer?->is_active && ($viewer->isSuperAdmin() || $viewer->roleFor($family) !== null) ? 'members' : 'public';
    }

    /**
     * @param  array{q?: ?string, profession?: ?string, skill?: ?string, sector?: ?string, city?: ?string, business_category?: ?string, entity?: ?int}  $filters
     */
    public function search(?User $viewer, Family $family, array $filters): Builder
    {
        $level = $this->viewerLevel($viewer, $family);
        $fields = $level === 'members' ? 'member_fields' : 'public_fields';
        $text = $level === 'members' ? 'search_members' : 'search_public';
        $visible = fn (Builder $query, string $field) => $query->where($fields, 'like', '%|'.$field.'|%');
        // Wildcards are dropped rather than escaped: SQLite and PostgreSQL treat LIKE escapes differently.
        $like = fn (string $value) => '%'.str_replace(['%', '_'], '', mb_strtolower($this->normalizer->normalize($value))).'%';

        return $this->listed($family, $level)
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->where($text, 'like', $like($filters['q'])))
            ->when(filled($filters['profession'] ?? null), fn (Builder $query) => $visible($query, 'profession')->where(fn (Builder $any) => $any->where('profession', 'like', '%'.str_replace(['%', '_'], '', $filters['profession']).'%')->orWhere('specialization', 'like', '%'.str_replace(['%', '_'], '', $filters['profession']).'%')))
            ->when(filled($filters['skill'] ?? null), fn (Builder $query) => $visible($query, 'skills')->where('skills', 'like', '%'.str_replace(['%', '_'], '', $filters['skill']).'%'))
            ->when(filled($filters['sector'] ?? null), fn (Builder $query) => $visible($query, 'sector')->where('sector', $filters['sector']))
            ->when(filled($filters['city'] ?? null), fn (Builder $query) => $visible($query, 'city')->where('city', $filters['city']))
            ->when(filled($filters['business_category'] ?? null), fn (Builder $query) => $visible($query, 'business')->where('business_category', $filters['business_category']))
            ->when(filled($filters['entity'] ?? null), function (Builder $query) use ($family, $filters, $visible): void {
                $entity = CommunityEntity::query()->where('family_id', $family->id)->whereKey($filters['entity'])->first();
                // The hierarchy decides the scope: an entity's filter includes its sub-entities.
                $visible($query, 'affiliation')->where('affiliation_status', 'verified')->whereIn('entity_id', $entity ? $this->hierarchy->descendantIds($entity) : [0]);
            })
            ->orderBy('display_name')->orderBy('id');
    }

    /** Listed, not suspended, and open to this audience. */
    public function listed(Family $family, string $level): Builder
    {
        return MemberProfile::query()->where('family_id', $family->id)->where('is_listed', true)->whereNull('suspended_at')
            ->when($level === 'public', fn (Builder $query) => $query->where('audience', 'public'));
    }

    public function canView(?User $viewer, MemberProfile $profile): bool
    {
        if ($viewer && $profile->user_id === $viewer->id) {
            return true;
        }

        return $profile->isVisibleInDirectory() && ($profile->audience === 'public' || $this->viewerLevel($viewer, $profile->family) === 'members');
    }

    /** @return array<int, string> */
    public function visibleFields(?User $viewer, MemberProfile $profile): array
    {
        return $viewer && $profile->user_id === $viewer->id ? $profile->fieldsFor('owner') : $profile->fieldsFor($this->viewerLevel($viewer, $profile->family));
    }

    /** A message delivered in-app; neither side's e-mail or phone is revealed. */
    public function contact(User $sender, MemberProfile $profile, string $message): void
    {
        abort_unless($this->canView($sender, $profile) && $profile->user_id !== $sender->id && in_array('messages', $this->visibleFields($sender, $profile), true), 403);
        $this->notifier->send(collect([$profile->user]), 'directory_contact', 'طلب تواصل من '.$sender->name.' عبر الدليل', mb_substr($message, 0, 1000), null);
        $this->audit->record($sender, $profile->family, $profile, 'contact_requested', null, ['to_profile' => $profile->id]);
    }

    public function report(User $reporter, MemberProfile $profile, string $reason, ?string $details): DirectoryReport
    {
        abort_unless($this->canView($reporter, $profile), 404);
        $report = $profile->reports()->create(['reporter_id' => $reporter->id, 'reason' => $reason, 'details' => $details]);
        $this->notifier->send($this->admins($profile->family), 'directory_report', 'بلاغ عن ملف في الدليل', DirectoryReport::REASONS[$reason], route('management.directory.review', $profile->family->slug));

        return $report;
    }

    public function reviewAffiliation(User $admin, MemberProfile $profile, string $decision, ?string $note): void
    {
        $this->authorizeAdmin($admin, $profile->family);
        if ($profile->affiliation_status !== 'pending' || ! in_array($decision, ['verified', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'لا يوجد طلب انتماء بانتظار القرار.']);
        }
        DB::transaction(function () use ($admin, $profile, $decision, $note): void {
            $before = $this->audit->snapshot($profile);
            $profile->forceFill(['affiliation_status' => $decision, 'affiliation_reviewed_by' => $admin->id, 'affiliation_reviewed_at' => now(), 'affiliation_note' => $note]);
            $this->index($profile);
            $profile->save();
            $this->audit->record($admin, $profile->family, $profile, 'affiliation_'.$decision, $before, $this->audit->snapshot($profile));
        });
        $this->notifier->send(collect([$profile->user]), 'directory_affiliation_'.$decision, $decision === 'verified' ? 'وُثّق انتماؤك في الدليل' : 'لم يُعتمد انتماؤك في الدليل', (string) $note, route('management.directory.edit', $profile->family->slug));
    }

    /** Dismiss a report, or suspend the listing (the member's data is never edited). */
    public function resolveReport(User $admin, DirectoryReport $report, string $action, ?string $note): void
    {
        $profile = $report->profile;
        $this->authorizeAdmin($admin, $profile->family);
        DB::transaction(function () use ($admin, $report, $profile, $action, $note): void {
            $report->forceFill(['status' => $action === 'suspend' ? 'actioned' : 'dismissed', 'resolved_by' => $admin->id, 'resolved_at' => now(), 'resolution_note' => $note])->save();
            if ($action === 'suspend') {
                $this->suspend($admin, $profile, $note ?: DirectoryReport::REASONS[$report->reason]);
            }
        });
    }

    public function suspend(User $admin, MemberProfile $profile, ?string $note): void
    {
        $this->authorizeAdmin($admin, $profile->family);
        $before = $this->audit->snapshot($profile);
        $profile->forceFill(['suspended_at' => now(), 'suspended_by' => $admin->id, 'suspension_note' => $note])->save();
        $this->audit->record($admin, $profile->family, $profile, 'suspended', $before, $this->audit->snapshot($profile));
        $this->notifier->send(collect([$profile->user]), 'directory_suspended', 'أُوقف ظهور ملفك في الدليل مؤقتًا', (string) $note, route('management.directory.edit', $profile->family->slug));
    }

    public function reinstate(User $admin, MemberProfile $profile): void
    {
        $this->authorizeAdmin($admin, $profile->family);
        $before = $this->audit->snapshot($profile);
        $profile->forceFill(['suspended_at' => null, 'suspended_by' => null, 'suspension_note' => null])->save();
        $this->audit->record($admin, $profile->family, $profile, 'reinstated', $before, $this->audit->snapshot($profile));
    }

    /** Precomputes, per audience, which fields are readable and the normalized text search may match. */
    public function index(MemberProfile $profile): void
    {
        foreach (['members' => ['member_fields', 'search_members'], 'public' => ['public_fields', 'search_public']] as $level => [$fieldsColumn, $textColumn]) {
            $fields = $profile->fieldsFor($level);
            $values = [$profile->display_name];
            $map = [
                'profession' => [$profile->profession, $profile->specialization], 'sector' => [MemberProfile::SECTORS[$profile->sector] ?? null],
                'skills' => $profile->skills ?? [], 'experience' => [$profile->experience], 'organization' => [$profile->organization],
                'business' => [$profile->business_name, MemberProfile::BUSINESS_CATEGORIES[$profile->business_category] ?? null, $profile->services],
                'city' => [$profile->city], 'governorate' => [$profile->governorate], 'collaboration' => [$profile->collaboration],
                'affiliation' => [$profile->affiliation_status === 'verified' ? $profile->entity?->name_ar : null],
            ];
            foreach ($map as $field => $fieldValues) {
                if (in_array($field, $fields, true)) {
                    $values = [...$values, ...$fieldValues];
                }
            }
            $profile->forceFill([
                $fieldsColumn => '|'.implode('|', $fields).'|',
                $textColumn => mb_strtolower($this->normalizer->normalize(implode(' ', array_filter($values, fn ($value) => is_string($value) && $value !== '')))),
            ]);
        }
    }

    /** @return Collection<int, User> */
    private function admins(Family $family)
    {
        return User::query()->where('is_active', true)->whereIn('id', FamilyMembership::query()->where('family_id', $family->id)->where('role', FamilyMembership::ROLE_ADMIN)->select('user_id'))->get();
    }

    private function authorizeAdmin(User $admin, Family $family): void
    {
        abort_unless($admin->can('manageMembers', $family), 403);
    }
}
