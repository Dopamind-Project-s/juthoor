<?php

namespace App\Services;

use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use App\Notifications\FamilyInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The consent path into a family: membership is created only when the invited address accepts (UC-08).
 * Pending invitations for the same address are replaced so a single link stays valid.
 */
class FamilyInvitationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function invite(Family $family, User $inviter, string $email, string $role): FamilyInvitation
    {
        $email = Str::lower($email);
        if ($family->users()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'المستخدم عضو في العائلة بالفعل.']);
        }
        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($family, $inviter, $email, $role, $token): FamilyInvitation {
            Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            foreach ($family->invitations()->where('email', $email)->whereNull('accepted_at')->whereNull('cancelled_at')->get() as $previous) {
                $before = $this->audit->snapshot($previous);
                $previous->update(['cancelled_at' => now()]);
                $this->audit->record($inviter, $family, $previous, 'cancelled', $before, $this->audit->snapshot($previous));
            }
            $invitation = $family->invitations()->create([
                'invited_by' => $inviter->id, 'email' => $email, 'role' => $role,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
            ]);
            $this->audit->record($inviter, $family, $invitation, 'invited', null, $this->audit->snapshot($invitation));

            return $invitation;
        });
        Notification::route('mail', $email)->notify(new FamilyInvitationNotification($invitation, $token));

        return $invitation;
    }
}
