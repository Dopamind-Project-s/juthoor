@extends('layouts.management')
@section('page-heading','لوحة إدارة الموقع')
@section('title','إدارة الموقع')
@section('content')
@php
    $levelLabels = ['ok' => 'سليم', 'warning' => 'تنبيه', 'danger' => 'خطر'];
    $levelClasses = ['ok' => 'text-bg-success', 'warning' => 'text-bg-warning', 'danger' => 'text-bg-danger'];
    $problems = collect($checks)->where('level', 'danger');
@endphp
<div class="family-dashboard-head"><div><span class="status-badge">سوبر أدمن</span><h1 class="h3 mt-2 mb-1">نظرة عامة على الموقع</h1><p class="text-muted mb-0">هذه لوحة الموقع كاملًا. لإدارة بيانات عائلة بعينها افتح لوحة تلك العائلة.</p></div></div>
@if($problems->isNotEmpty())
<div class="alert alert-danger" role="alert"><strong>مشكلات تشغيلية تحتاج إلى تدخل:</strong><ul class="mb-0">@foreach($problems as $check)<li>{{ $check['label'] }}: {{ $check['hint'] ?? $check['value'] }}</li>@endforeach</ul></div>
@endif
<div class="dashboard-stats" aria-label="إحصاءات الموقع">
@foreach(['families'=>'العائلات','active_families'=>'العائلات النشطة','trees'=>'الأشجار','people'=>'الأشخاص','users'=>'المستخدمون','inactive_users'=>'حسابات معطلة','unverified_users'=>'بريد غير مؤكد','super_admins'=>'سوبر أدمن نشط','memberships'=>'العضويات','pending_invitations'=>'دعوات معلقة','pending_changes'=>'مقترحات معلقة','pending_shared_ancestry'=>'مطابقات قيد المراجعة','pending_imports'=>'دفعات CSV معلقة','failed_jobs'=>'مهام فاشلة','audit'=>'عمليات التدقيق'] as $key=>$label)
<div class="dashboard-stat"><small>{{ $label }}</small><strong>{{ number_format($statistics[$key]) }}</strong></div>
@endforeach
</div>
<div class="dashboard-columns">
<section class="management-panel" aria-labelledby="systemHeading"><span class="eyebrow">التشغيل</span><h2 class="h4" id="systemHeading">صحة النظام</h2>
<div class="table-responsive"><table class="table site-checks mb-0"><thead><tr><th scope="col">الفحص</th><th scope="col">الحالة</th><th scope="col">القيمة</th></tr></thead><tbody>
@foreach($checks as $check)<tr><th scope="row">{{ $check['label'] }}</th><td><span class="badge {{ $levelClasses[$check['level']] }}">{{ $levelLabels[$check['level']] }}</span></td><td>{{ $check['value'] }}@if($check['hint'])<small class="d-block text-muted">{{ $check['hint'] }}</small>@endif</td></tr>@endforeach
</tbody></table></div></section>
<section class="management-panel" aria-labelledby="attentionHeading"><span class="eyebrow">تحذيرات</span><h2 class="h4" id="attentionHeading">عائلات تحتاج إلى متابعة</h2>
<div class="management-list">@forelse($attention as $row)<article><div><strong>{{ $row['family']->name_ar }}</strong><small>{{ implode(' · ', $row['issues']) }}</small></div><a class="btn btn-sm btn-outline-primary" href="{{ route('management.families.dashboard',$row['family']->slug) }}">لوحة العائلة</a></article>@empty<p class="text-muted mb-0" role="status">لا توجد عائلة نشطة دون مدير أو شجرة أساسية أو جذر.</p>@endforelse</div>
@if($statistics['super_admins'] < 2)<p class="alert alert-warning small mt-3 mb-0">يوجد سوبر أدمن نشط واحد فقط. أضف حسابًا ثانيًا محميًا لتجنب فقدان إدارة الموقع.</p>@endif
</section>
</div>
<section class="management-panel" aria-label="أقسام إدارة الموقع"><h2 class="h4">إدارة الموقع</h2><div class="management-cards">
@foreach(['families'=>'العائلات','trees'=>'الأشجار','users'=>'المستخدمون','memberships'=>'العضويات','invitations'=>'الدعوات','audit'=>'سجل التدقيق العام'] as $key=>$label)
<a class="management-panel" href="{{ route('site-admin.resources.index',$key) }}"><h3 class="h5">{{ $label }}</h3><span>عرض وإدارة السجلات ←</span></a>
@endforeach
<a class="management-panel" href="{{ route('site-admin.attributes.index') }}"><h3 class="h5">صفات الأشخاص المشتركة</h3><span>البيانات المرجعية ←</span></a>
<a class="management-panel" href="{{ route('cross-links.index') }}"><h3 class="h5">الروابط بين العائلات</h3><span>{{ $statistics['pending_shared_ancestry'] }} بانتظار المراجعة ←</span></a>
</div></section>
<section class="management-panel"><h2 class="h4">العائلات</h2><div class="management-list">
@forelse($families as $item)<article><div><strong>{{ $item->name_ar }}</strong><small>{{ $item->family_trees_count }} شجرة · {{ $item->users_count }} عضو · {{ $item->is_active ? 'نشطة' : 'معطلة' }}</small></div><a class="btn btn-outline-primary" href="{{ route('management.families.dashboard',$item->slug) }}">لوحة العائلة</a></article>@empty<p>لا توجد عائلات بعد.</p>@endforelse
</div>{{ $families->links() }}</section>
<section class="management-panel"><h2 class="h4">آخر العمليات في الموقع</h2><div class="management-list">@forelse($auditLogs as $log)<article><div><strong>{{ $log->action }} · {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</strong><small>{{ $log->family?->name_ar ?? 'على مستوى الموقع' }} · {{ $log->user?->name ?? 'النظام' }} · {{ $log->created_at }}</small></div><a href="{{ route('management.audit.show',$log->id) }}" class="btn btn-outline-primary">تفاصيل</a></article>@empty<p>لا توجد عمليات مسجلة بعد.</p>@endforelse</div>{{ $auditLogs->links() }}</section>
@endsection
