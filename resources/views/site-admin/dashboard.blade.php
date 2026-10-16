@extends('layouts.management')
@section('breadcrumb','إدارة الموقع')
@section('page-heading','لوحة السوبر أدمن')
@section('title','إدارة الموقع')
@section('content')
<h1 class="h3 mb-4">نظرة عامة على الموقع</h1>
<div class="dashboard-stats">
@foreach(['families'=>'العائلات','active_families'=>'العائلات النشطة','trees'=>'الأشجار','people'=>'الأشخاص','users'=>'المستخدمون','memberships'=>'العضويات','pending_invitations'=>'دعوات معلقة','audit'=>'عمليات التدقيق'] as $key=>$label)
<div class="dashboard-stat"><small>{{ $label }}</small><strong>{{ number_format($statistics[$key]) }}</strong></div>
@endforeach
</div>
<div class="management-cards">
@foreach(['families'=>'العائلات','trees'=>'الأشجار','users'=>'المستخدمون','memberships'=>'العضويات','invitations'=>'الدعوات','audit'=>'سجل التدقيق'] as $key=>$label)
<a class="management-panel" href="{{ route('site-admin.resources.index',$key) }}"><h2 class="h5">{{ $label }}</h2><span>عرض وإدارة السجلات ←</span></a>
@endforeach
</div>
<section class="management-panel"><h2 class="h4">العائلات</h2><div class="management-list">
@forelse($families as $item)<article><div><strong>{{ $item->name_ar }}</strong><small>{{ $item->family_trees_count }} شجرة · {{ $item->users_count }} عضو</small></div><a class="btn btn-outline-primary" href="{{ route('management.families.show',$item->slug) }}">لوحة العائلة</a></article>@empty<p>لا توجد عائلات بعد.</p>@endforelse
</div>{{ $families->links() }}</section>
<section class="management-panel"><h2 class="h4">آخر العمليات</h2><div class="management-list">@forelse($auditLogs as $log)<article><div><strong>{{ $log->action }}</strong><small>{{ $log->user?->name ?? 'النظام' }} · {{ $log->created_at }}</small></div><a href="{{ route('management.audit.show',$log->id) }}" class="btn btn-outline-primary">تفاصيل</a></article>@empty<p>لا توجد عمليات مسجلة بعد.</p>@endforelse</div>{{ $auditLogs->links() }}</section>
@endsection
