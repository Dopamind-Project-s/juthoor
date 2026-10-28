@extends('layouts.admin-shell')
@section('workspace-identity')
<a class="admin-workspace-brand" href="{{ route('management.families.dashboard', $workspaceFamily->slug) }}">
@if($adminWorkspace['logo'])<img class="workspace-avatar" src="{{ $adminWorkspace['logo']->url() }}" alt="شعار {{ $workspaceFamily->name_ar }}">@else<span class="workspace-avatar" aria-hidden="true">{{ mb_substr($workspaceFamily->name_ar, 0, 1) }}</span>@endif
<span><strong>{{ $adminWorkspace['branding']['display_name'] }}</strong><small>مساحة إدارة العائلة</small></span></a>
<p class="admin-scope-note">{{ $roleLabels[$workspaceRole] ?? 'عضو' }}@if($adminWorkspace['roles']) · {{ implode('، ', $adminWorkspace['roles']) }}@endif</p>
@unless($workspaceFamily->is_active)<span class="badge text-bg-warning">عائلة معطلة</span>@endunless
@endsection
