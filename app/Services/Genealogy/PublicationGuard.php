<?php

namespace App\Services\Genealogy;

use App\Models\Branch;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Only family administrators (and super admins) may publish genealogy or change what is already public.
 * Editors keep direct write access to unpublished records; published records change through reviewed proposals (UC-07).
 */
class PublicationGuard
{
    public const PUBLISHED_STATUSES = [Source::VERIFICATION_VERIFIED, Source::VERIFICATION_SOURCED];

    /**
     * Call after filling the record and before saving (or before deleting it).
     */
    public function assertAllowed(User $user, Family $family, Model $record, bool $deleting = false): void
    {
        if ($this->canPublish($user, $family)) {
            return;
        }

        $wasPublic = $record->exists && $this->isPublic($record, $record->getOriginal());
        $willBePublic = ! $deleting && $this->isPublic($record, $record->getAttributes());
        if ($wasPublic) {
            throw ValidationException::withMessages(['change' => 'هذا السجل منشور للعامة؛ تعديله أو حذفه يمر بطلب تغيير يعتمده مدير العائلة.']);
        }
        if ($willBePublic) {
            throw ValidationException::withMessages(['verification_status' => 'اعتماد حالة «موثق» أو «مسند» للنشر من صلاحية مدير العائلة؛ احفظ السجل «قيد المراجعة» أو قدّم طلب تغيير.']);
        }
    }

    /**
     * Mirrors FamilyPolicy::publish without the gate's account refresh, because services such as
     * ChangeReviewService receive users authorized earlier by their controllers.
     */
    public function canPublish(User $user, Family $family): bool
    {
        return $user->isSuperAdmin() || $user->roleFor($family) === FamilyMembership::ROLE_ADMIN;
    }

    /** @param array<string, mixed> $attributes */
    public function isPublic(Model $record, array $attributes): bool
    {
        $verified = in_array($attributes['verification_status'] ?? null, self::PUBLISHED_STATUSES, true)
            || (($attributes['verification_status'] ?? null) === Source::VERIFICATION_PENDING_REVIEW && (bool) ($attributes['public_narrative'] ?? false));

        return match (true) {
            $record instanceof Person => $verified && ($attributes['status'] ?? null) === Person::STATUS_HISTORICAL && in_array($attributes['is_living'] ?? null, [false, 0, '0'], true),
            $record instanceof Branch => $verified && (bool) ($attributes['is_active'] ?? false),
            $record instanceof PersonRelationship => $verified,
            default => false,
        };
    }
}
