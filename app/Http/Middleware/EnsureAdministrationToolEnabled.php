<?php

namespace App\Http\Middleware;

use App\Support\MaintenanceTools;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Optional administration screens only; policies and integrity checks always remain active. */
final class EnsureAdministrationToolEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $resource = $request->route('resource') ?? $request->route('section');
        $audit = $request->routeIs('management.audit.*', 'management.recoveries.*') || $resource === 'audit';
        $permissions = $request->routeIs('site-admin.roles.*', 'site-admin.users.*', 'management.roles.*', 'management.memberships.*', 'invitations.store', 'invitations.update', 'invitations.cancel', 'invitations.resend')
            || in_array($resource, ['users', 'members', 'memberships', 'invitations'], true);
        $genealogy = $request->routeIs('management.people.*', 'management.relationships.*', 'management.branches.*', 'management.trees.*', 'site-admin.trees.*', 'management.imports.*', 'management.sources.*', 'management.changes.*', 'management.merges.*', 'management.attributes.*', 'site-admin.attributes.*', 'management.reconciliation.*', 'cross-links.*')
            || in_array($resource, ['people', 'relationships', 'branches', 'trees'], true);
        foreach (['audit_viewer' => $audit, 'permissions_administration' => $permissions, 'genealogy_administration' => $genealogy] as $tool => $applies) {
            abort_if($applies && ! MaintenanceTools::enabled($tool), 403, 'هذه الوظيفة موقوفة مؤقتًا من إدارة الموقع.');
        }

        return $next($request);
    }
}
