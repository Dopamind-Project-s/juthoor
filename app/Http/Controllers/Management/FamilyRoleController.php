<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\Role;
use App\Services\Access\FamilyPermissions;
use App\Services\Access\PermissionCatalog;
use App\Services\AuditLogger;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Functional roles of one family. Assignments are scoped to a membership of that family; an assigner may only grant
 * permissions they hold, and nobody but a super admin changes their own roles.
 */
class FamilyRoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly FamilyPermissions $permissions) {}

    /** Role and permission matrix of the family with current holders. */
    public function index(Request $request, string $family): View
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('manageMembers', $familyModel);

        return view('management.roles.index', [
            'family' => $familyModel,
            'tree' => app(SelectedFamilyTree::class)->resolve($familyModel),
            'roles' => Role::query()->assignable()->with('permissions')->orderBy('is_system', 'desc')->orderBy('id')->get(),
            'holders' => $familyModel->memberships()->with(['user:id,name,email', 'functionalRoles:id,key'])->whereHas('functionalRoles')->get(),
            'effectivePermissions' => array_values(array_intersect($this->permissions->for($request->user(), $familyModel), PermissionCatalog::familyPermissionKeys())),
            'canAssign' => $request->user()->can('assignRoles', $familyModel),
        ]);
    }

    public function update(Request $request, string $family, int $membership): RedirectResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('assignRoles', $familyModel);
        $membershipModel = $familyModel->memberships()->whereKey($membership)->firstOrFail();
        $actor = $request->user();
        if ($membershipModel->user_id === $actor->id && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['role_ids' => 'لا يمكنك تعديل أدوارك بنفسك؛ يسندها مدير آخر.']);
        }
        $data = $request->validate([
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('scope', PermissionCatalog::SCOPE_FAMILY)->whereNull('deprecated_at')],
        ]);
        $requested = Role::query()->with('permissions')->whereIn('id', $data['role_ids'] ?? [])->get();

        DB::transaction(function () use ($familyModel, $membershipModel, $requested, $actor): void {
            Family::query()->whereKey($familyModel->id)->lockForUpdate()->firstOrFail();
            $current = $membershipModel->functionalRoles()->pluck('roles.id')->all();
            $added = $requested->reject(fn (Role $role) => in_array($role->id, $current, true));
            $held = $this->permissions->for($actor, $familyModel);
            foreach ($added as $role) {
                $beyond = array_diff($role->permissionKeys(), $held);
                if ($beyond !== []) {
                    throw ValidationException::withMessages(['role_ids' => 'لا يمكنك إسناد الدور «'.$role->label_ar.'» لأنه يمنح صلاحيات لا تملكها: '.implode('، ', $beyond).'.']);
                }
            }
            $before = ['functional_roles' => Role::query()->whereIn('id', $current)->orderBy('key')->pluck('key')->all()];
            // Kept roles keep their original assigner; only new ones record the current actor.
            $membershipModel->functionalRoles()->sync($requested->mapWithKeys(fn (Role $role) => [$role->id => in_array($role->id, $current, true) ? [] : ['assigned_by' => $actor->id]])->all());
            $after = ['functional_roles' => $requested->sortBy('key')->pluck('key')->values()->all()];
            if ($before !== $after) {
                $this->audit->record($actor, $familyModel, $membershipModel, 'roles.updated', $before + ['user_id' => $membershipModel->user_id], $after + ['user_id' => $membershipModel->user_id]);
            }
        });

        return back()->with('status', 'حُفظت الأدوار الوظيفية للعضو وسُجلت في التدقيق.');
    }
}
