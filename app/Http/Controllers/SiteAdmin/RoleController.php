<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Access\PermissionCatalog;
use App\Services\Access\RoleCatalogSynchronizer;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Platform governance of functional role templates: catalog status (dry run), administrator customization and custom
 * roles. A customized role is never rewritten by later catalog runs; platform permissions are never grantable.
 */
class RoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly RoleCatalogSynchronizer $synchronizer) {}

    public function index(): View
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        return view('site-admin.roles.index', [
            'roles' => Role::query()->with('permissions')->withCount('assignments')->orderBy('is_system', 'desc')->orderBy('id')->get(),
            'permissions' => Permission::query()->where('scope', PermissionCatalog::SCOPE_FAMILY)->whereNull('deprecated_at')->orderBy('id')->get(),
            'report' => $this->synchronizer->sync(dryRun: true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'max:64', Rule::unique('roles', 'key'), Rule::notIn(array_keys(PermissionCatalog::ROLES))],
            'label_ar' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($data, $request): void {
            $role = Role::query()->create($data + ['scope' => PermissionCatalog::SCOPE_FAMILY, 'is_system' => false, 'customized_at' => now()]);
            $this->audit->record($request->user(), null, $role, 'rbac.role.created', null, $this->synchronizer->roleSnapshot($role));
        });

        return back()->with('status', 'أُنشئ الدور المخصص دون صلاحيات؛ حدد صلاحياته ثم أسنده من صفحات العائلات.');
    }

    public function update(Request $request, int $role): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $roleModel = Role::query()->where('scope', PermissionCatalog::SCOPE_FAMILY)->findOrFail($role);
        $data = $request->validate([
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')->where('scope', PermissionCatalog::SCOPE_FAMILY)->whereNull('deprecated_at')],
            'label_ar' => ['required', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($roleModel, $data, $request): void {
            $roleModel = Role::query()->lockForUpdate()->findOrFail($roleModel->id);
            $before = $this->synchronizer->roleSnapshot($roleModel);
            $current = $roleModel->permissions()->pluck('origin', 'permissions.id')->all();
            $roleModel->permissions()->sync(collect($data['permission_ids'] ?? [])->mapWithKeys(fn ($id) => [(int) $id => ['origin' => $current[(int) $id] ?? Role::ORIGIN_MANUAL]])->all());
            $roleModel->fill(['label_ar' => $data['label_ar']]);
            $after = $this->synchronizer->roleSnapshot($roleModel);
            if ($after !== $before) {
                // From now on the catalog reports differences for this role instead of changing it.
                $roleModel->customized_at = now();
                $roleModel->save();
                $this->audit->record($request->user(), null, $roleModel, 'rbac.role.customized', $before, $this->synchronizer->roleSnapshot($roleModel));
            }
        });

        return back()->with('status', 'حُفظت صلاحيات الدور وسُجل التغيير في التدقيق.');
    }
}
