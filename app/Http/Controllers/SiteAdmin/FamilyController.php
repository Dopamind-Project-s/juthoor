<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Notifications\FamilyInvitationNotification;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FamilyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin();
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', 'unique:families,slug'],
            'tree_name_ar' => ['required', 'string', 'max:255'],
            'manager_email' => ['nullable', 'email'],
        ]);

        DB::transaction(function () use ($data, $request): void {
            $family = Family::query()->create([
                'name_ar' => $data['name_ar'], 'slug' => $data['slug'], 'is_active' => true,
            ]);
            $family->familyTrees()->create([
                'name_ar' => $data['tree_name_ar'], 'slug' => 'main', 'is_primary' => true, 'is_active' => true,
            ]);
            if (! empty($data['manager_email'])) {
                $email = Str::lower($data['manager_email']);
                $manager = User::query()->where('email', $email)->first();
                if ($manager && ! $manager->is_active) {
                    throw ValidationException::withMessages(['manager_email' => 'حساب المدير معطل.']);
                }
                if ($manager) {
                    $membership = $family->memberships()->create(['user_id' => $manager->id, 'role' => FamilyMembership::ROLE_ADMIN]);
                    $this->audit->record($request->user(), $family, $membership, 'created', null, $this->audit->snapshot($membership));
                } else {
                    $token = Str::random(64);
                    $invitation = $family->invitations()->create([
                        'invited_by' => $request->user()->id, 'email' => $email, 'role' => FamilyMembership::ROLE_ADMIN,
                        'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
                    ]);
                    $this->audit->record($request->user(), $family, $invitation, 'invited', null, $this->audit->snapshot($invitation));
                    Notification::route('mail', $email)
                        ->notify(new FamilyInvitationNotification($invitation, $token));
                }
            }
            $this->audit->record($request->user(), $family, $family, 'created', null, $this->audit->snapshot($family));
        });

        return back()->with('status', 'تم إنشاء العائلة وشجرتها الأساسية.');
    }

    public function update(Request $request, int $family): RedirectResponse
    {
        $this->authorizeSuperAdmin();
        $familyModel = Family::query()->findOrFail($family);
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique('families')->ignore($familyModel)],
            'is_active' => ['nullable', 'boolean'],
            'tree_is_active' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($data, $familyModel, $request): void {
            Family::query()->whereKey($familyModel->id)->lockForUpdate()->firstOrFail();
            $before = $this->audit->snapshot($familyModel);
            $familyModel->update(['name_ar' => $data['name_ar'], 'slug' => $data['slug'], 'is_active' => $request->boolean('is_active')]);
            $familyModel->primaryTree()->update(['is_active' => $request->boolean('tree_is_active')]);
            $this->audit->record($request->user(), $familyModel, $familyModel, 'updated', $before, $this->audit->snapshot($familyModel));
        });

        return back()->with('status', 'تم تحديث حالة العائلة والشجرة.');
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);
    }
}
