@extends('layouts.management')
@section('page-heading','إدارة منصة جذور')
@section('title','إدارة منصة جذور')
@section('content')
@php
    $levelLabels = ['ok' => 'سليم', 'warning' => 'تنبيه', 'danger' => 'خطر'];
    $levelClasses = ['ok' => 'text-bg-success', 'warning' => 'text-bg-warning', 'danger' => 'text-bg-danger'];
    $problems = collect($checks)->where('level', 'danger');
@endphp
<x-admin.page-header title="إدارة منصة جذور" eyebrow="حوكمة المنصة" description="نظرة مركزية على العائلات والعضويات والأعمال التي تحتاج إلى متابعة. افتح مساحة العائلة لإدارة سجلاتها."><a class="btn btn-primary" href="{{ route('site-admin.resources.create','families') }}">إضافة عائلة</a><a class="btn btn-outline-primary" href="{{ route('management.families.index') }}">مساحات العائلات</a></x-admin.page-header>
@if($problems->isNotEmpty())
<div class="alert alert-danger" role="alert"><strong>مشكلات تشغيلية تحتاج إلى تدخل:</strong><ul class="mb-0">@foreach($problems as $check)<li>{{ $check['label'] }}: {{ $check['hint'] ?? $check['value'] }}</li>@endforeach</ul></div>
@endif
<div class="dashboard-stats" aria-label="إحصاءات الموقع">
@foreach(['families'=>'العائلات','active_families'=>'العائلات النشطة','trees'=>'الأشجار','users'=>'المستخدمون','memberships'=>'العضويات','published_entities'=>'كيانات بحالة منشور','pending_changes'=>'مقترحات الأنساب المعلقة','pending_invitations'=>'دعوات معلقة'] as $key=>$label)
<x-admin.stat-card :label="$label" :value="$statistics[$key]" />
@endforeach
</div>
<section aria-labelledby="platform-actions"><h2 class="h5" id="platform-actions">مركز المتابعة</h2><div class="admin-action-grid">@if($maintenanceTools['permissions_administration']['enabled'])<a class="admin-action-card" href="{{ route('site-admin.resources.index',['invitations','status'=>'pending']) }}"><strong>{{ $statistics['pending_invitations'] }}</strong><span>دعوات بانتظار القبول</span></a>@endif
@if($maintenanceTools['genealogy_administration']['enabled'])<a class="admin-action-card" href="{{ route('cross-links.index') }}"><strong>{{ $statistics['pending_shared_ancestry'] }}</strong><span>روابط عائلية بانتظار المراجعة</span></a>@endif<a class="admin-action-card" href="#families-overview"><strong>{{ $statistics['pending_changes'] }}</strong><div><span>مقترحات الأنساب المعلقة</span><small>اختر العائلة لفتح قائمة مراجعتها</small></div></a><a class="admin-action-card" href="{{ route('site-admin.domains.index') }}"><i class="bi bi-globe2" aria-hidden="true"></i><span>مراجعة نطاقات العائلات</span></a></div></section>
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
@if(($key !== 'trees' || $maintenanceTools['genealogy_administration']['enabled']) && (!in_array($key,['users','memberships','invitations']) || $maintenanceTools['permissions_administration']['enabled']) && ($key !== 'audit' || $maintenanceTools['audit_viewer']['enabled']))<a class="management-panel" href="{{ route('site-admin.resources.index',$key) }}"><h3 class="h5">{{ $label }}</h3><span>عرض وإدارة السجلات ←</span></a>@endif
@endforeach
@if($maintenanceTools['genealogy_administration']['enabled'])<a class="management-panel" href="{{ route('site-admin.attributes.index') }}"><h3 class="h5">صفات الأشخاص المشتركة</h3><span>البيانات المرجعية ←</span></a>
<a class="management-panel" href="{{ route('cross-links.index') }}"><h3 class="h5">الروابط بين العائلات</h3><span>{{ $statistics['pending_shared_ancestry'] }} بانتظار المراجعة ←</span></a>@endif
</div></section>
<section class="management-panel" id="families-overview"><h2 class="h4">العائلات</h2><div class="management-list">
@forelse($families as $item)<article><div><strong>{{ $item->name_ar }}</strong><small>{{ $item->family_trees_count }} شجرة · {{ $item->users_count }} عضو · {{ $item->is_active ? 'نشطة' : 'معطلة' }}</small></div><a class="btn btn-outline-primary" href="{{ route('management.families.dashboard',$item->slug) }}">لوحة العائلة</a></article>@empty<p>لا توجد عائلات بعد.</p>@endforelse
</div>{{ $families->links() }}</section>
<section class="management-panel"><h2 class="h4">آخر العمليات في الموقع</h2><div class="management-list">@forelse($auditLogs as $log)<article><div><strong>{{ $log->action }} · {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</strong><small>{{ $log->family?->name_ar ?? 'على مستوى الموقع' }} · {{ $log->user?->name ?? 'النظام' }} · {{ $log->created_at }}</small></div><a href="{{ route('management.audit.show',$log->id) }}" class="btn btn-outline-primary">تفاصيل</a></article>@empty<p>لا توجد عمليات مسجلة بعد.</p>@endforelse</div>{{ $auditLogs->links() }}</section>
<details class="management-panel admin-secondary"><summary>تفاصيل الحسابات والسجلات</summary><div class="dashboard-stats">@foreach(['people'=>'الأشخاص','inactive_users'=>'حسابات معطلة','unverified_users'=>'بريد غير مؤكد','super_admins'=>'سوبر أدمن نشط','pending_imports'=>'دفعات CSV معلقة','failed_jobs'=>'مهام فاشلة','audit'=>'عمليات التدقيق'] as $key=>$label)<x-admin.stat-card :label="$label" :value="$statistics[$key]" />@endforeach</div></details>
<details id="maintenance-tools" class="admin-secondary"><summary>أدوات الصيانة وإتاحة وظائف الإدارة</summary><section class="management-panel">
<h2 class="h4">أدوات الصيانة</h2>
<form method="POST" action="{{ route('site-admin.maintenance-tools.update') }}">
@csrf @method('PUT')
@foreach($maintenanceTools as $name => $tool)
<input type="hidden" name="{{ $name }}" value="0">
<label class="d-block mb-2"><input type="checkbox" name="{{ $name }}" value="1" @checked($tool['enabled'])> {{ $tool['label'] }}</label>
@endforeach
<button class="btn btn-primary">حفظ الإعدادات</button>
</form>
<p class="small text-muted mt-2">بعض الأدوات الاختيارية معطلة افتراضيًا؛ الحالة الحالية موضحة أعلاه. سجل التدقيق الأمني وحماية البيانات يبقيان فعالين. لا تُشغّل اختبارات PHP أو Node من الويب.</p>
</section>
</details>
@endsection
