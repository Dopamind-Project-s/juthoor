@extends('layouts.admin-shell')
@section('workspace-identity')
<a class="admin-workspace-brand" href="{{ route('site-admin.dashboard') }}"><span class="workspace-avatar"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><span><strong>جذور</strong><small>حوكمة المنصة · إدارة الموقع</small></span></a>
<p class="admin-scope-note">إدارة مركزية لكل العائلات. بيانات كل عائلة وصلاحياتها مستقلة.</p>
@endsection
