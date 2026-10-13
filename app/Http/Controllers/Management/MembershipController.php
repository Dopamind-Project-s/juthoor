<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreMembershipRequest;
use App\Http\Requests\Management\UpdateMembershipRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MembershipController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(StoreMembershipRequest $request, string $family): RedirectResponse
    {
        $familyModel = $this->family($family);
        $user = User::query()->where('email', $request->string('email'))->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => 'لا يوجد مستخدم مسجل بهذا البريد.']);
        }

        if ($familyModel->memberships()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'المستخدم عضو في هذه العائلة بالفعل.']);
        }

        DB::transaction(function () use ($familyModel, $user, $request): void {
            $membership = $familyModel->memberships()->create([
                'user_id' => $user->id,
                'role' => (string) $request->string('role'),
            ]);
            $this->audit->record($request->user(), $familyModel, $membership, 'created', null, $this->audit->snapshot($membership));
        });

        return back()->with('status', 'تمت إضافة عضو العائلة.');
    }

    public function update(UpdateMembershipRequest $request, string $family, int $membership): RedirectResponse
    {
        $familyModel = $this->family($family);
        $membershipModel = $familyModel->memberships()->whereKey($membership)->firstOrFail();

        DB::transaction(function () use ($familyModel, $membershipModel, $request): void {
            Family::query()->whereKey($familyModel->id)->lockForUpdate()->firstOrFail();
            $membershipModel->refresh();
            $this->protectLastAdmin($familyModel, $membershipModel, (string) $request->string('role'));
            $before = $this->audit->snapshot($membershipModel);
            $membershipModel->update(['role' => (string) $request->string('role')]);
            $this->audit->record($request->user(), $familyModel, $membershipModel, 'updated', $before, $this->audit->snapshot($membershipModel));
        });

        return back()->with('status', 'تم تحديث دور العضو.');
    }

    public function destroy(string $family, int $membership): RedirectResponse
    {
        $familyModel = $this->family($family);
        $membershipModel = $familyModel->memberships()->whereKey($membership)->firstOrFail();

        DB::transaction(function () use ($familyModel, $membershipModel): void {
            Family::query()->whereKey($familyModel->id)->lockForUpdate()->firstOrFail();
            $membershipModel->refresh();
            $this->protectLastAdmin($familyModel, $membershipModel, null);
            $before = $this->audit->snapshot($membershipModel);
            $membershipModel->delete();
            $this->audit->record(auth()->user(), $familyModel, $membershipModel, 'deleted', $before, null);
        });

        return back()->with('status', 'تمت إزالة العضوية.');
    }

    private function family(string $slug): Family
    {
        $family = Family::query()->where('slug', $slug)->firstOrFail();
        Gate::authorize('manageMembers', $family);

        return $family;
    }

    private function protectLastAdmin(Family $family, FamilyMembership $membership, ?string $newRole): void
    {
        if ($membership->role === FamilyMembership::ROLE_ADMIN && $membership->user->is_active
            && $newRole !== FamilyMembership::ROLE_ADMIN
            && $family->memberships()->where('role', FamilyMembership::ROLE_ADMIN)->whereHas('user', fn ($query) => $query->where('is_active', true))->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'يجب أن يبقى للعائلة مدير واحد على الأقل.']);
        }
    }
}
