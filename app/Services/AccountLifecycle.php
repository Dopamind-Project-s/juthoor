<?php

namespace App\Services;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AccountLifecycle
{
    public function assertCanRemoveActiveUser(User $user): void
    {
        if (! $user->is_active) {
            return;
        }
        $familyIds = $user->familyMemberships()->where('role', FamilyMembership::ROLE_ADMIN)->pluck('family_id');
        Family::query()->whereKey($familyIds)->orderBy('id')->lockForUpdate()->get(['id']);
        $memberships = $user->familyMemberships()->where('role', FamilyMembership::ROLE_ADMIN)->get();
        foreach ($memberships as $membership) {
            $otherActiveAdmin = FamilyMembership::query()->where('family_id', $membership->family_id)
                ->where('role', FamilyMembership::ROLE_ADMIN)->where('user_id', '!=', $user->id)
                ->whereHas('user', fn ($query) => $query->where('is_active', true))->exists();
            if (! $otherActiveAdmin) {
                throw ValidationException::withMessages(['user' => 'عيّن مديرًا نشطًا آخر للعائلة قبل تعطيل أو حذف مديرها الأخير.']);
            }
        }
    }
}
