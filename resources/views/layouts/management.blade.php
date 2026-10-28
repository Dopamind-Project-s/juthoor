@php
    $workspaceFamily = ! request()->routeIs('site-admin.*') && isset($family) && $family instanceof \App\Models\Family ? $family : null;
    $workspace = $workspaceFamily ? 'family' : (auth()->user()->isSuperAdmin() && request()->routeIs('site-admin.*', 'cross-links.*') ? 'site' : 'personal');
    $roleLabels = \App\Services\Dashboards\FamilyDashboardService::roleLabels();
    $workspaceRole = $workspaceFamily ? (auth()->user()->isSuperAdmin() ? 'super_admin' : auth()->user()->roleFor($workspaceFamily)) : null;
@endphp
@extends($workspace === 'family' ? 'layouts.family-admin' : ($workspace === 'site' ? 'layouts.platform-admin' : 'layouts.admin-shell'))
