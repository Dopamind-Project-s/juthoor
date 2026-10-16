@extends('layouts.management')
@section('title', $title)
@section('page-heading', $title)
@section('breadcrumb', 'إدارة الموقع / '.$title)
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><h1 class="h3">{{ $title }} <small class="text-muted">({{ $records->total() }})</small></h1>@if($resource !== 'audit')<a class="btn btn-primary" href="{{ route('site-admin.resources.create', $resource) }}">إضافة سجل</a>@endif</div>
<section class="management-panel">
<form method="get" class="management-grid mb-4">
<label>بحث<input type="search" class="form-control" name="q" value="{{ request('q') }}" placeholder="ابحث بالاسم أو البريد أو الوصف"></label>
@if(in_array($resource,['trees','memberships','invitations','audit']))<label>العائلة<select name="family_id" class="form-select"><option value="">كل العائلات</option>@foreach($families as $item)<option value="{{ $item->id }}" @selected(request('family_id') == $item->id)>{{ $item->name_ar }}</option>@endforeach</select></label>@endif
@if(in_array($resource,['families','trees','users']))<label>الحالة<select name="active" class="form-select"><option value="">كل الحالات</option><option value="1" @selected(request('active')==='1')>نشط</option><option value="0" @selected(request('active')==='0')>معطل</option></select></label>@endif
@if(in_array($resource,['memberships','invitations']))<label>الدور<select name="role" class="form-select"><option value="">كل الأدوار</option>@foreach(['admin'=>'مدير','editor'=>'محرر','reader'=>'قارئ'] as $value=>$label)<option value="{{ $value }}" @selected(request('role')===$value)>{{ $label }}</option>@endforeach</select></label>@endif
@if($resource==='invitations')<label>حالة الدعوة<select name="status" class="form-select"><option value="">الكل</option>@foreach(['pending'=>'معلقة','accepted'=>'مقبولة','cancelled'=>'ملغاة','expired'=>'منتهية'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></label>@endif
<div class="d-flex gap-2 align-items-end"><button class="btn btn-primary">بحث وتصفية</button><a class="btn btn-outline-primary" href="{{ route('site-admin.resources.index',$resource) }}">مسح</a></div>
</form>
<div class="management-list">
@forelse($records as $record)
<article><div><strong>{{ $record->name_ar ?? $record->name ?? $record->email ?? $record->user?->name ?? $record->action }}</strong><small>{{ $record->family?->name_ar }} · {{ $record->email ?? $record->slug ?? $record->action ?? '' }} @if($record->role) · {{ ['admin'=>'مدير','editor'=>'محرر','reader'=>'قارئ'][$record->role] ?? $record->role }}@endif</small></div><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="{{ route('site-admin.resources.show',[$resource,$record->id]) }}">تفاصيل</a>@if($resource!=='audit')<a class="btn btn-sm btn-primary" href="{{ route('site-admin.resources.edit',[$resource,$record->id]) }}">إدارة وتعديل</a>@endif</div></article>
@empty<div class="empty-state"><h2 class="h5">لا توجد سجلات</h2><p>غيّر معايير البحث أو أضف سجلًا جديدًا.</p></div>@endforelse
</div>{{ $records->links() }}
</section>
@endsection
