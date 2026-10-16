<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountLifecycle;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'username' => ['nullable', 'alpha_dash:ascii', 'lowercase', 'max:64', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'is_active' => ['nullable', 'boolean'], 'is_super_admin' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($data, $request): void {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'], 'email' => strtolower($data['email']),
                'username' => $data['username'] ?? null, 'password' => $data['password'],
                'is_active' => $request->boolean('is_active'), 'is_super_admin' => $request->boolean('is_super_admin'),
            ])->save();
            $this->audit->record($request->user(), null, $user, 'created', null, $this->audit->snapshot($user));
        });

        return redirect()->route('site-admin.resources.index', 'users')->with('status', 'تم إنشاء المستخدم. يمكنه تأكيد بريده بعد الدخول.');
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $userModel = User::query()->findOrFail($user);
        $profile = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($userModel)],
            'username' => ['sometimes', 'nullable', 'alpha_dash:ascii', 'lowercase', 'max:64', Rule::unique('users')->ignore($userModel)],
            'is_active' => ['nullable', 'boolean'], 'is_super_admin' => ['nullable', 'boolean'],
        ]);
        $nextActive = $request->boolean('is_active');
        $nextSuperAdmin = $request->boolean('is_super_admin');

        DB::transaction(function () use ($request, $userModel, $nextActive, $nextSuperAdmin, $profile): void {
            $this->lockAccountChanges($request, $userModel);
            $this->protectLastSuperAdmin($userModel, $nextActive && $nextSuperAdmin);
            if (! $nextActive) {
                app(AccountLifecycle::class)->assertCanRemoveActiveUser($userModel);
            }
            $before = $this->audit->snapshot($userModel);
            if (! $nextActive) {
                $userModel->setRememberToken(null);
                DB::table('sessions')->where('user_id', $userModel->id)->delete();
            }
            if (isset($profile['email']) && $profile['email'] !== $userModel->email) {
                $userModel->email_verified_at = null;
            }
            $userModel->fill(collect($profile)->only(['name', 'email', 'username'])->all());
            $userModel->forceFill(['is_active' => $nextActive, 'is_super_admin' => $nextSuperAdmin])->save();
            $action = $before['is_super_admin'] !== $userModel->is_super_admin ? 'super_admin_changed' : 'updated';
            $this->audit->record($request->user(), null, $userModel, $action, $before, $this->audit->snapshot($userModel));
        });

        return back()->with('status', 'تم تحديث حساب المستخدم.');
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $userModel = User::query()->findOrFail($user);
        DB::transaction(function () use ($request, $userModel): void {
            $this->lockAccountChanges($request, $userModel);
            $this->protectLastSuperAdmin($userModel, false, true);
            app(AccountLifecycle::class)->assertCanRemoveActiveUser($userModel);
            DB::table('sessions')->where('user_id', $userModel->id)->delete();
            $before = $this->audit->snapshot($userModel);
            $this->audit->record($request->user(), null, $userModel, 'deleted', $before, null);
            $userModel->delete();
        });

        return redirect()->route('site-admin.resources.index', 'users')->with('status', 'تم حذف المستخدم.');
    }

    private function lockAccountChanges(Request $request, User $user): void
    {
        User::query()->where('is_super_admin', true)->orWhere('id', $user->id)->orderBy('id')->lockForUpdate()->get(['id']);
        $user->refresh();
        abort_unless($request->user()->fresh()?->isSuperAdmin(), 403);
    }

    private function protectLastSuperAdmin(User $user, bool $remainsSuperAdmin, bool $deleting = false): void
    {
        if ($user->isSuperAdmin() && ! $remainsSuperAdmin
            && User::query()->where('is_super_admin', true)->where('is_active', true)->count() === 1) {
            throw ValidationException::withMessages(['user' => $deleting
                ? 'لا يمكن حذف آخر سوبر أدمن نشط.'
                : 'لا يمكن تعطيل أو سحب صلاحية آخر سوبر أدمن نشط.']);
        }
    }
}
