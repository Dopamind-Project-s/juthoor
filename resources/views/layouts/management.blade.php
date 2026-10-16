<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title','الإدارة') | جذور</title><link href="{{ asset('vendor/cairo/cairo.css') }}" rel="stylesheet"><link href="{{ asset('vendor/bootstrap/bootstrap.rtl.min.css') }}" rel="stylesheet"><link href="{{ asset('vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet"><link href="{{ asset('css/juthoor.css') }}" rel="stylesheet"></head><body class="management-shell">
<aside class="management-sidebar" id="managementSidebar"><a class="brand text-white" href="{{ route('home') }}"><span class="brand-mark"><i class="bi bi-tree-fill"></i></span><span>جذور<small>لوحة الإدارة</small></span></a><div class="management-family-switch"><label for="familySwitch">العائلة الحالية</label><select id="familySwitch" class="form-select" onchange="if(this.value) location.href=this.value"><option value="{{ route('management.families.index') }}">كل العائلات</option>@foreach($accessibleFamilies as $item)<option value="{{ route('management.families.show',$item->slug) }}" @selected(isset($family)&&$family->slug===$item->slug)>{{ $item->name_ar }}</option>@endforeach</select></div><nav aria-label="التنقل الإداري">
<a href="{{ route('management.families.index') }}"><i class="bi bi-grid"></i> لوحة العائلات</a>
@if(isset($family))
<a href="{{ route('management.families.show',[$family->slug,'tree'=>$tree->slug ?? null]) }}"><i class="bi bi-house"></i> لوحة العائلة</a>
@foreach(['people'=>'الأشخاص','relationships'=>'العلاقات','branches'=>'الفروع','trees'=>'الأشجار','members'=>'الأعضاء','invitations'=>'الدعوات','audit'=>'سجل التغييرات'] as $key=>$label)
@if(!in_array($key,['members','invitations']) || auth()->user()->isSuperAdmin() || auth()->user()->roleFor($family)==='admin')
<a href="{{ route('management.sections.index',[$family->slug,$key,'tree'=>$tree->slug ?? null]) }}">{{ $label }}</a>
@endif
@endforeach
@can('manageSources',$family)<a href="{{ route('management.imports.index',[$family->slug,'tree'=>$tree->slug ?? null]) }}">استيراد CSV ومعاينة</a>@endcan
<a href="{{ route('management.sources.index',[$family->slug,'tree'=>$tree->slug ?? null]) }}">المصادر والاستشهادات</a>
<a href="{{ route('management.changes.index',[$family->slug,'tree'=>$tree->slug ?? null]) }}">طلبات التغيير والمراجعة</a>
<a href="{{ route('genealogy.tree',[$family->slug,'tree'=>$tree->slug ?? null,'private'=>1]) }}">الشجرة التفاعلية</a>
@endif
@if(auth()->user()->isSuperAdmin())
<hr><a href="{{ route('site-admin.dashboard') }}"><i class="bi bi-shield-lock"></i> لوحة السوبر أدمن</a>
@foreach(['families'=>'العائلات','trees'=>'الأشجار','users'=>'المستخدمون','memberships'=>'العضويات','invitations'=>'الدعوات','audit'=>'سجل التدقيق'] as $key=>$label)
<a href="{{ route('site-admin.resources.index',$key) }}">{{ $label }}</a>
@endforeach
@endif
</nav></aside>
<div class="management-main"><header class="management-topbar"><button class="btn btn-light d-lg-none" data-sidebar-toggle aria-label="فتح القائمة" aria-controls="managementSidebar" aria-expanded="false"><i class="bi bi-list"></i></button><div><small>@yield('breadcrumb','الإدارة')</small><strong>@yield('page-heading','جذور')</strong></div><div class="ms-auto d-flex align-items-center gap-2"><span class="status-badge">{{ auth()->user()->name }}</span><form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-sm btn-outline-primary">خروج</button></form></div></header><main class="management-content">@if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif @if($errors->any())<div class="alert alert-danger" role="alert" tabindex="-1"><strong>تعذر تنفيذ العملية</strong><ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif @yield('content')</main></div><div class="sidebar-backdrop" data-sidebar-backdrop></div><script src="{{ asset('js/juthoor.js') }}"></script>@stack('scripts')</body></html>
