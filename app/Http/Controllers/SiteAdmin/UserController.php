<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(Request $request, int $user): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $userModel = User::query()->findOrFail($user);
        $request->validate(['is_active' => ['nullable', 'boolean'], 'is_super_admin' => ['nullable', 'boolean']]);
        $nextActive = $request->boolean('is_active');
        $nextSuperAdmin = $request->boolean('is_super_admin');

        if ($userModel->isSuperAdmin() && (! $nextActive || ! $nextSuperAdmin)
            && User::query()->where('is_super_admin', true)->where('is_active', true)->count() === 1) {
            throw ValidationException::withMessages(['user' => 'لا يمكن تعطيل أو سحب صلاحية آخر سوبر أدمن نشط.']);
        }

        DB::transaction(function () use ($request, $userModel, $nextActive, $nextSuperAdmin): void {
            $before = $this->audit->snapshot($userModel);
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
        if ($userModel->isSuperAdmin()
            && User::query()->where('is_super_admin', true)->where('is_active', true)->count() === 1) {
            throw ValidationException::withMessages(['user' => 'لا يمكن حذف آخر سوبر أدمن نشط.']);
        }
        DB::transaction(function () use ($request, $userModel): void {
            $before = $this->audit->snapshot($userModel);
            $this->audit->record($request->user(), null, $userModel, 'deleted', $before, null);
            $userModel->delete();
        });

        return back()->with('status', 'تم حذف المستخدم.');
    }
}
