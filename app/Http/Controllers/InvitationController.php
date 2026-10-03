<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Notifications\FamilyInvitationNotification;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, string $family): RedirectResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('manageMembers', $familyModel);
        $data = $request->validate(['email' => ['required', 'email'], 'role' => ['required', 'in:reader,editor,admin']]);
        $email = Str::lower($data['email']);
        if ($familyModel->users()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'المستخدم عضو في العائلة بالفعل.']);
        }
        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($familyModel, $request, $data, $email, $token): FamilyInvitation {
            $invitation = $familyModel->invitations()->create([
                'invited_by' => $request->user()->id, 'email' => $email, 'role' => $data['role'],
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
            ]);
            $this->audit->record($request->user(), $familyModel, $invitation, 'invited', null, $this->audit->snapshot($invitation));

            return $invitation;
        });
        Notification::route('mail', $email)->notify(new FamilyInvitationNotification($invitation, $token));

        return back()->with('status', 'تم إرسال الدعوة لمدة سبعة أيام.');
    }

    public function show(string $token): View
    {
        $invitation = $this->invitation($token);

        return view('invitations.show', compact('invitation', 'token'));
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitation($token);
        if (! $invitation->isAcceptable()) {
            throw ValidationException::withMessages(['invitation' => 'الدعوة منتهية أو ملغاة أو سبق قبولها.']);
        }
        $user = $request->user();
        if (! $user) {
            return redirect()->route('invitations.register', $token);
        }
        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw ValidationException::withMessages(['invitation' => 'هذه الدعوة مرتبطة ببريد إلكتروني مختلف.']);
        }

        DB::transaction(function () use ($invitation, $user): void {
            $invitation->family->memberships()->updateOrCreate(
                ['user_id' => $user->id], ['role' => $invitation->role],
            );
            $before = $this->audit->snapshot($invitation);
            $user->markEmailAsVerified();
            $invitation->update(['accepted_by' => $user->id, 'accepted_at' => now()]);
            $this->audit->record($user, $invitation->family, $invitation, 'accepted', $before, $this->audit->snapshot($invitation));
        });

        return redirect()->route('management.families.show', $invitation->family->slug)->with('status', 'تم قبول الدعوة.');
    }

    public function cancel(Request $request, string $family, int $invitation): RedirectResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('manageMembers', $familyModel);
        $invitationModel = $familyModel->invitations()->whereKey($invitation)->firstOrFail();
        DB::transaction(function () use ($invitationModel, $familyModel, $request): void {
            $before = $this->audit->snapshot($invitationModel);
            $invitationModel->update(['cancelled_at' => now()]);
            $this->audit->record($request->user(), $familyModel, $invitationModel, 'cancelled', $before, $this->audit->snapshot($invitationModel));
        });

        return back()->with('status', 'تم إلغاء الدعوة.');
    }

    private function invitation(string $token): FamilyInvitation
    {
        return FamilyInvitation::query()->with('family')->where('token_hash', hash('sha256', $token))->firstOrFail();
    }
}
