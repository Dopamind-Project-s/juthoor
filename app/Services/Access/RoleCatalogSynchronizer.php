<?php

namespace App\Services\Access;

use App\Models\FamilyRoleAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Installs and updates the permission catalog idempotently.
 *
 * - Missing permissions and role templates are created; nothing is duplicated (stable keys).
 * - Withdrawn catalog grants are removed (least privilege); new grants for an existing role are only reported unless
 *   upgrades are explicitly applied — a rerun never silently widens a role.
 * - Roles customized by an administrator, manual grants, custom roles and user assignments are never changed.
 * - Renamed permissions move their grants to the new key; removed ones are deprecated (kept, granting nothing).
 * - Every applied change is audited; a dry run computes the same report and rolls back.
 */
class RoleCatalogSynchronizer
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{catalog_version: string, dry_run: bool, permissions: array{created: array<int, string>, relabelled: array<int, string>, restored: array<int, string>, renamed: array<string, string>, deprecated: array<int, string>, conflicts: array<int, string>}, roles: array{created: array<int, string>, in_sync: array<int, string>, granted: array<string, array<int, string>>, pending_grants: array<string, array<int, string>>, revoked: array<string, array<int, string>>, customized: array<string, array{missing: array<int, string>, extra: array<int, string>}>, deprecated: array<int, string>, conflicts: array<int, string>}, custom_roles: array<int, string>, assignments: int}
     */
    public function sync(bool $dryRun = false, bool $applyUpgrades = false, ?User $actor = null): array
    {
        $report = [
            'catalog_version' => PermissionCatalog::VERSION, 'dry_run' => $dryRun,
            'permissions' => ['created' => [], 'relabelled' => [], 'restored' => [], 'renamed' => [], 'deprecated' => [], 'conflicts' => []],
            'roles' => ['created' => [], 'in_sync' => [], 'granted' => [], 'pending_grants' => [], 'revoked' => [], 'customized' => [], 'deprecated' => [], 'conflicts' => []],
            'custom_roles' => [], 'assignments' => 0,
        ];

        DB::beginTransaction();
        try {
            $permissions = $this->syncPermissions($report, $actor);
            $this->syncRoles($report, $permissions, $applyUpgrades, $actor);
            $report['custom_roles'] = Role::query()->where('is_system', false)->orderBy('key')->pluck('key')->all();
            $report['assignments'] = FamilyRoleAssignment::query()->count();
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        return $report;
    }

    /** @return array<string, Permission> active catalog permissions by key */
    private function syncPermissions(array &$report, ?User $actor): array
    {
        $existing = Permission::query()->get()->keyBy('key');
        $active = [];
        foreach (PermissionCatalog::PERMISSIONS as $key => $definition) {
            $permission = $existing->get($key);
            if (! $permission) {
                $permission = Permission::query()->create(['key' => $key, 'scope' => $definition['scope'], 'label_ar' => $definition['label'], 'catalog_version' => PermissionCatalog::VERSION]);
                $report['permissions']['created'][] = $key;
                $this->audit->record($actor, null, $permission, 'rbac.permission.created', null, $this->audit->snapshot($permission));
            } elseif ($permission->scope !== $definition['scope']) {
                // A scope change could make a platform right grantable inside a family; it needs a reviewed migration.
                $report['permissions']['conflicts'][] = $key;

                continue;
            } else {
                $before = $this->audit->snapshot($permission);
                if ($permission->deprecated_at) {
                    $permission->forceFill(['deprecated_at' => null, 'replaced_by' => null]);
                    $report['permissions']['restored'][] = $key;
                }
                if ($permission->label_ar !== $definition['label']) {
                    $permission->label_ar = $definition['label'];
                    $report['permissions']['relabelled'][] = $key;
                }
                if ($permission->isDirty()) {
                    $permission->catalog_version = PermissionCatalog::VERSION;
                    $permission->save();
                    $this->audit->record($actor, null, $permission, 'rbac.permission.updated', $before, $this->audit->snapshot($permission));
                }
            }
            $active[$key] = $permission;
        }

        foreach (PermissionCatalog::RENAMED as $old => $new) {
            $previous = $existing->get($old);
            if (! $previous || $previous->deprecated_at || ! isset($active[$new])) {
                continue;
            }
            foreach ($previous->roles()->get() as $role) {
                $role->permissions()->syncWithoutDetaching([$active[$new]->id => ['origin' => $role->pivot->origin]]);
            }
            $before = $this->audit->snapshot($previous);
            $previous->forceFill(['deprecated_at' => now(), 'replaced_by' => $new])->save();
            $report['permissions']['renamed'][$old] = $new;
            $this->audit->record($actor, null, $previous, 'rbac.permission.renamed', $before, $this->audit->snapshot($previous));
        }

        foreach ($existing as $key => $permission) {
            if (! isset(PermissionCatalog::PERMISSIONS[$key]) && ! $permission->deprecated_at && ! isset(PermissionCatalog::RENAMED[$key])) {
                $before = $this->audit->snapshot($permission);
                $permission->forceFill(['deprecated_at' => now()])->save();
                $report['permissions']['deprecated'][] = $key;
                $this->audit->record($actor, null, $permission, 'rbac.permission.deprecated', $before, $this->audit->snapshot($permission));
            }
        }

        return $active;
    }

    /** @param array<string, Permission> $permissions */
    private function syncRoles(array &$report, array $permissions, bool $applyUpgrades, ?User $actor): void
    {
        $existing = Role::query()->with('permissions')->get()->keyBy('key');
        foreach (PermissionCatalog::ROLES as $key => $template) {
            $role = $existing->get($key);
            $templateKeys = collect($template['permissions'])->filter(fn (string $permission) => isset($permissions[$permission]))->sort()->values();
            if (! $role) {
                $role = Role::query()->create(['key' => $key, 'scope' => PermissionCatalog::SCOPE_FAMILY, 'label_ar' => $template['label'], 'description' => $template['description'], 'is_system' => true, 'catalog_version' => PermissionCatalog::VERSION]);
                $role->permissions()->attach($templateKeys->mapWithKeys(fn (string $permission) => [$permissions[$permission]->id => ['origin' => Role::ORIGIN_CATALOG]])->all());
                $report['roles']['created'][] = $key;
                $this->audit->record($actor, null, $role, 'rbac.role.created', null, $this->roleSnapshot($role));

                continue;
            }
            if (! $role->is_system) {
                // An administrator-created role already uses this key; never take it over.
                $report['roles']['conflicts'][] = $key;

                continue;
            }

            $granted = $role->permissions->whereNull('deprecated_at');
            $current = $granted->pluck('key')->sort()->values();
            $missing = $templateKeys->diff($current)->values();
            $withdrawn = $granted->filter(fn (Permission $permission) => $permission->pivot->origin === Role::ORIGIN_CATALOG && ! $templateKeys->contains($permission->key))->pluck('key')->values();

            if ($role->customized_at) {
                if ($missing->isNotEmpty() || $current->diff($templateKeys)->isNotEmpty()) {
                    $report['roles']['customized'][$key] = ['missing' => $missing->all(), 'extra' => $current->diff($templateKeys)->values()->all()];
                } else {
                    $report['roles']['in_sync'][] = $key;
                }

                continue;
            }

            $before = $this->roleSnapshot($role);
            if ($withdrawn->isNotEmpty()) {
                $role->permissions()->detach($withdrawn->map(fn (string $permission) => $permissions[$permission]->id ?? Permission::query()->where('key', $permission)->value('id'))->all());
                $report['roles']['revoked'][$key] = $withdrawn->all();
            }
            if ($missing->isNotEmpty()) {
                if ($applyUpgrades) {
                    $role->permissions()->attach($missing->mapWithKeys(fn (string $permission) => [$permissions[$permission]->id => ['origin' => Role::ORIGIN_CATALOG]])->all());
                    $report['roles']['granted'][$key] = $missing->all();
                } else {
                    $report['roles']['pending_grants'][$key] = $missing->all();
                }
            }
            $role->fill(['label_ar' => $template['label'], 'description' => $template['description']]);
            if (! isset($report['roles']['pending_grants'][$key])) {
                $role->catalog_version = PermissionCatalog::VERSION;
            }
            $role->save();
            $role->load('permissions');
            $after = $this->roleSnapshot($role);
            if ($after !== $before) {
                $this->audit->record($actor, null, $role, 'rbac.role.synced', $before, $after);
            } elseif (! isset($report['roles']['pending_grants'][$key])) {
                $report['roles']['in_sync'][] = $key;
            }
        }

        foreach ($existing as $key => $role) {
            if ($role->is_system && ! isset(PermissionCatalog::ROLES[$key]) && ! $role->deprecated_at) {
                $before = $this->roleSnapshot($role);
                $role->forceFill(['deprecated_at' => now()])->save();
                $report['roles']['deprecated'][] = $key;
                $this->audit->record($actor, null, $role, 'rbac.role.deprecated', $before, $this->roleSnapshot($role));
            }
        }
    }

    /** @return array<string, mixed> */
    public function roleSnapshot(Role $role): array
    {
        return $this->audit->snapshot($role) + ['permissions' => $role->permissions()->orderBy('key')->get()->map(fn (Permission $permission) => $permission->key.':'.$permission->pivot->origin)->all()];
    }
}
