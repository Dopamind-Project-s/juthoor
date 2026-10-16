@extends('layouts.management')
@section('title', $title)
@section('page-heading', $title)
@section('breadcrumb', 'إدارة الموقع / '.$title)
@section('content')
<div class="d-flex justify-content-between mb-4"><h1 class="h3">{{ ['create'=>'إضافة','edit'=>'تعديل وإدارة','show'=>'تفاصيل'][$mode] }} — {{ $title }}</h1><a href="{{ route('site-admin.resources.index',$resource) }}" class="btn btn-outline-primary">العودة للقائمة</a></div>
@if($mode==='show')
<section class="management-panel">
<dl class="row">
@foreach(['id'=>'الرقم','name_ar'=>'الاسم','name'=>'الاسم','username'=>'اسم المستخدم','email'=>'البريد الإلكتروني','slug'=>'الرابط المختصر','role'=>'الدور','is_active'=>'نشط','is_super_admin'=>'سوبر أدمن','is_primary'=>'شجرة أساسية','action'=>'العملية','auditable_type'=>'نوع السجل','auditable_id'=>'رقم السجل','created_at'=>'تاريخ الإنشاء','expires_at'=>'انتهاء الدعوة','accepted_at'=>'قبول الدعوة','cancelled_at'=>'إلغاء الدعوة'] as $key=>$label)
@if($record->getAttribute($key)!==null)<dt class="col-sm-4">{{ $label }}</dt><dd class="col-sm-8">{{ is_bool($record->$key) ? ($record->$key ? 'نعم' : 'لا') : $record->$key }}</dd>@endif
@endforeach
@if($selectedFamily)<dt class="col-sm-4">العائلة</dt><dd class="col-sm-8">{{ $selectedFamily->name_ar }}</dd>@endif
@if($resource==='memberships')<dt class="col-sm-4">العضو</dt><dd class="col-sm-8">{{ $record->user->name }} · {{ $record->user->email }}</dd>@endif
</dl>
@if($resource==='audit')<a class="btn btn-primary" href="{{ route('management.audit.show',$record->id) }}">عرض التغييرات والتراجع المصرح</a><p class="text-muted mt-3">سجل التدقيق يُنشأ تلقائيًا ولا يُعدل حفاظًا على سجل العمليات.</p>
@else<a class="btn btn-primary" href="{{ route('site-admin.resources.edit',[$resource,$record->id]) }}">إدارة وتعديل</a>@endif
@if($resource==='families')<a class="btn btn-outline-primary" href="{{ route('management.families.show',$record->slug) }}">لوحة العائلة</a>@endif
@if($resource==='trees')<a class="btn btn-outline-primary" href="{{ route('management.families.show',[$selectedFamily->slug,'tree'=>$record->slug]) }}">إدارة الشجرة والأشخاص</a>@endif
</section>
@else
@if(in_array($resource,['trees','memberships','invitations']) && !$record)
<section class="management-panel"><form method="get" class="management-grid"><label>اختر العائلة<select class="form-select" name="family_id" required><option value="">اختر العائلة</option>@foreach($families as $item)<option value="{{ $item->id }}" @selected($selectedFamily?->id===$item->id)>{{ $item->name_ar }}</option>@endforeach</select></label><button class="btn btn-outline-primary">متابعة</button></form></section>
@endif
@if(!in_array($resource,['trees','memberships','invitations']) || $selectedFamily)
@php
$action = match($resource) {
'families' => $record ? route('site-admin.families.update',$record->id) : route('site-admin.families.store'),
'trees' => $record ? route('site-admin.trees.update',[$selectedFamily->id,$record->id]) : route('site-admin.trees.store',$selectedFamily->id),
'users' => $record ? route('site-admin.users.update',$record->id) : route('site-admin.users.store'),
'memberships' => $record ? route('management.memberships.update',[$selectedFamily->slug,$record->id]) : route('management.memberships.store',$selectedFamily->slug),
'invitations' => $record ? route('invitations.update',[$selectedFamily->slug,$record->id]) : route('invitations.store',$selectedFamily->slug),
};
@endphp
<section class="management-panel">
@if($resource==='invitations' && $record && ($record->accepted_at || $record->cancelled_at))
<p class="alert alert-info">هذه الدعوة مقبولة أو ملغاة ولا يمكن تعديلها. يمكنك إنشاء دعوة جديدة.</p>
@else
<form method="post" action="{{ $action }}" class="management-grid">@csrf @if($record) @method('PUT') @endif
@if(in_array($resource,['families','trees']))
<label>الاسم بالعربية<input class="form-control" name="name_ar" value="{{ old('name_ar',$record?->name_ar) }}" placeholder="الاسم الكامل" required></label>
@if(!$record || $resource==='families')<label>الرابط المختصر<input class="form-control" name="slug" value="{{ old('slug',$record?->slug) }}" placeholder="family-name" dir="ltr" required></label>@endif
@if($resource==='families' && !$record)<label>اسم الشجرة الأساسية<input class="form-control" name="tree_name_ar" value="{{ old('tree_name_ar') }}" placeholder="الشجرة الأساسية" required></label><label>بريد مدير العائلة (اختياري)<input class="form-control" name="manager_email" type="email" value="{{ old('manager_email') }}" placeholder="manager@example.com"></label>@endif
@if($record)<label><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$record->is_active))> نشط</label>@endif
@if($resource==='families' && $record)<label><input type="checkbox" name="tree_is_active" value="1" @checked(old('tree_is_active',$record->primaryTree?->is_active))> الشجرة الأساسية نشطة</label>@endif
@if($resource==='trees')<label><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary',$record?->is_primary))> الشجرة الأساسية</label>@endif
@endif
@if($resource==='users')
<label>الاسم<input name="name" class="form-control" value="{{ old('name',$record?->name) }}" placeholder="الاسم الكامل" required></label>
<label>البريد الإلكتروني<input name="email" type="email" class="form-control" value="{{ old('email',$record?->email) }}" placeholder="name@example.com" required></label>
<label>اسم المستخدم (اختياري)<input name="username" class="form-control" value="{{ old('username',$record?->username) }}" placeholder="username" dir="ltr"></label>
@if(!$record)<label>كلمة المرور<input name="password" class="form-control" type="password" minlength="8" autocomplete="new-password" required></label><label>تأكيد كلمة المرور<input name="password_confirmation" class="form-control" type="password" autocomplete="new-password" required></label>@endif
<label><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$record?->is_active ?? true))> حساب نشط</label>
<label><input type="checkbox" name="is_super_admin" value="1" @checked(old('is_super_admin',$record?->is_super_admin))> صلاحية السوبر أدمن</label>
@endif
@if(in_array($resource,['memberships','invitations']))
@if(!$record)<label>البريد الإلكتروني<input type="email" name="email" class="form-control" value="{{ old('email') }}" placeholder="name@example.com" required></label>@else<p>{{ $record->email ?? $record->user->email }}</p>@endif
<label>الدور<select name="role" class="form-select">@foreach(['reader'=>'قارئ','editor'=>'محرر','admin'=>'مدير'] as $value=>$label)<option value="{{ $value }}" @selected(old('role',$record?->role)===$value)>{{ $label }}</option>@endforeach</select></label>
@endif
@if($resource==='trees' || ($resource==='families' && !$record))
@include('management.trees.root-fields',['rootTree'=>$resource==='trees' ? $record : null])
@endif
<div><button class="btn btn-primary">حفظ البيانات</button></div>
</form>
@endif
@if($record && $resource==='users')<form class="mt-4" method="post" action="{{ route('site-admin.users.destroy',$record->id) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">حذف المستخدم</button></form>@endif
@if($record && $resource==='memberships')<form class="mt-4" method="post" action="{{ route('management.memberships.destroy',[$selectedFamily->slug,$record->id]) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">إزالة العضوية</button></form>@endif
@if($record && $resource==='invitations' && !$record->accepted_at && !$record->cancelled_at)<div class="d-flex gap-2 mt-4"><form method="post" action="{{ route('invitations.resend',[$selectedFamily->slug,$record->id]) }}">@csrf<button class="btn btn-outline-primary">إعادة إرسال وتجديد الدعوة</button></form><form method="post" action="{{ route('invitations.cancel',[$selectedFamily->slug,$record->id]) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">إلغاء الدعوة</button></form></div>@endif
</section>
@endif
@endif
@endsection
