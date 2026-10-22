<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use App\Notifications\FamilyInvitationNotification;
use App\Services\AuditLogger;
use App\Services\FamilyInvitationService;
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
        abort_unless($familyModel->is_active, 403);
        $data = $request->validate(['email' => ['required', 'email'], 'role' => ['required', 'in:reader,editor,admin']]);
        app(FamilyInvitationService::class)->invite($familyModel, $request->user(), $data['email'], $data['role']);

        return back()->with('status', 'أُضيفت الدعوة إلى طابور البريد لمدة سبعة أيام.');
    }

    public function show(string $token): View
    {
        $invitation = $this->invitation($token);

        $existingUser = User::query()->where('email', $invitation->email)->exists();

        return view('invitations.show', compact('invitation', 'token', 'existingUser'));
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

        DB::transaction(function () use ($invitation, $user, $token): void {
            Family::query()->whereKey($invitation->family_id)->lockForUpdate()->firstOrFail();
            $invitation = FamilyInvitation::query()->with('family')->lockForUpdate()->findOrFail($invitation->id);
            if (! $invitation->isAcceptable() || ! hash_equals($invitation->token_hash, hash('sha256', $token)) || $invitation->family->memberships()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['invitation' => 'الدعوة غير متاحة أو المستخدم عضو بالفعل.']);
            }
            $invitation->family->memberships()->create(['user_id' => $user->id, 'role' => $invitation->role]);
            $before = $this->audit->snapshot($invitation);
            $user->markEmailAsVerified();
            $invitation->update(['accepted_by' => $user->id, 'accepted_at' => now()]);
            $this->audit->record($user, $invitation->family, $invitation, 'accepted', $before, $this->audit->snapshot($invitation));
        });

        return redirect()->route('management.families.show', $invitation->family->slug)->with('status', 'تم قبول الدعوة.');
    }

    public function resend(Request $request, string $family, int $invitation): RedirectResponse
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        Gate::authorize('manageMembers', $familyModel);
        $token = Str::random(64);
        $invitationModel = DB::transaction(function () use ($request, $familyModel, $invitation, $token): FamilyInvitation {
            $model = $familyModel->invitations()->lockForUpdate()->findOrFail($invitation);
            if ($model->accepted_at || $model->cancelled_at) {
                throw ValidationException::withMessages(['invitation' => 'لا يمكن إعادة إرسال دعوة مقبولة أو ملغاة.']);
            }
            $before = $this->audit->snapshot($model);
            $model->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'invited_by' => $request->user()->id]);
            $this->audit->record($request->user(), $familyModel, $model, 'resent', $before, $this->audit->snapshot($model));

            return $model;
        });
        Notification::route('mail', $invitationModel->email)->notify(new FamilyInvitationNotification($invitationModel, $token));

        return back()->with('status', 'أُضيفت الدعوة إلى طابور البريد وأُبطل الرابط السابق.');
    }

    public function update(Request $request, string $family, int $invitation): RedirectResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('manageMembers', $familyModel);
        $data = $request->validate(['role' => ['required', 'in:reader,editor,admin']]);
        DB::transaction(function () use ($familyModel, $invitation, $data, $request): void {
            $model = $familyModel->invitations()->lockForUpdate()->findOrFail($invitation);
            if ($model->accepted_at || $model->cancelled_at) {
                throw ValidationException::withMessages(['invitation' => 'لا يمكن تعديل دعوة مقبولة أو ملغاة.']);
            }
            $before = $this->audit->snapshot($model);
            $model->update($data);
            $this->audit->record($request->user(), $familyModel, $model, 'updated', $before, $this->audit->snapshot($model));
        });

        return back()->with('status', 'تم تحديث دور الدعوة.');
    }

    public function cancel(Request $request, string $family, int $invitation): RedirectResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('manageMembers', $familyModel);
        $invitationModel = $familyModel->invitations()->whereKey($invitation)->firstOrFail();
        DB::transaction(function () use ($invitationModel, $familyModel, $request): void {
            $invitationModel = $familyModel->invitations()->lockForUpdate()->findOrFail($invitationModel->id);
            if ($invitationModel->accepted_at || $invitationModel->cancelled_at) {
                throw ValidationException::withMessages(['invitation' => 'لا يمكن إلغاء دعوة مقبولة أو ملغاة.']);
            }
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
