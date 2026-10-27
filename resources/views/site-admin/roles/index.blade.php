@extends('layouts.management')
@section('title','الأدوار والصلاحيات')
@section('page-heading','إدارة الموقع / الأدوار والصلاحيات')
@section('content')
<section class="management-panel"><span class="eyebrow">كتالوج {{ $report['catalog_version'] }}</span><h1 class="h3">حالة الكتالوج (تشغيل تجريبي)</h1>
<p class="text-muted">لا يغيّر هذا العرض شيئًا. التثبيت والتحديث بالأمر <code>php artisan rbac:sync</code>؛ منح صلاحيات جديدة لقالب قائم يحتاج <code>--apply-upgrades</code> بعد المراجعة. الأدوار المعدلة والأدوار المخصصة وإسنادات المستخدمين لا تُمس.</p>
<dl class="row small">
<dt class="col-sm-3">صلاحيات ستُنشأ</dt><dd class="col-sm-9">{{ implode('، ', $report['permissions']['created']) ?: '—' }}</dd>
<dt class="col-sm-3">قوالب ستُنشأ</dt><dd class="col-sm-9">{{ implode('، ', $report['roles']['created']) ?: '—' }}</dd>
<dt class="col-sm-3">منح بانتظار الموافقة</dt><dd class="col-sm-9">@forelse($report['roles']['pending_grants'] as $roleKey=>$keys){{ $roleKey }}: {{ implode('، ',$keys) }}<br>@empty — @endforelse</dd>
<dt class="col-sm-3">أدوار معدلة إداريًا</dt><dd class="col-sm-9">@forelse($report['roles']['customized'] as $roleKey=>$difference){{ $roleKey }} (ناقص: {{ implode('، ',$difference['missing']) ?: '—' }} · إضافي: {{ implode('، ',$difference['extra']) ?: '—' }})<br>@empty — @endforelse</dd>
<dt class="col-sm-3">تعارضات</dt><dd class="col-sm-9">{{ implode('، ', [...$report['permissions']['conflicts'], ...$report['roles']['conflicts']]) ?: '—' }}</dd>
<dt class="col-sm-3">إسنادات قائمة</dt><dd class="col-sm-9">{{ $report['assignments'] }}</dd>
</dl></section>
@foreach($roles as $role)
<section class="management-panel" id="role-{{ $role->key }}"><div class="d-flex flex-wrap justify-content-between gap-2"><h2 class="h5 mb-0">{{ $role->label_ar }} <code class="small">{{ $role->key }}</code></h2><span class="small text-muted">{{ $role->is_system ? 'قالب النظام' : 'دور مخصص' }}{{ $role->customized_at ? ' · معدل '.$role->customized_at->format('Y-m-d') : '' }}{{ $role->deprecated_at ? ' · متقادم' : '' }} · {{ $role->assignments_count }} إسناد</span></div>
@if($role->description)<p class="text-muted small">{{ $role->description }}</p>@endif
<form method="post" action="{{ route('site-admin.roles.update',$role->id) }}" class="management-grid">@csrf @method('PUT')
<label>الاسم<input class="form-control" name="label_ar" value="{{ $role->label_ar }}" required maxlength="255"></label>
<fieldset class="connected-fields"><legend class="h6">الصلاحيات</legend>@foreach($permissions as $permission)<label><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked($role->permissions->contains('id',$permission->id))> {{ $permission->label_ar }} <code class="small">{{ $permission->key }}</code>@if(($role->permissions->firstWhere('id',$permission->id)?->pivot->origin) === 'manual') <span class="badge text-bg-light">يدوي</span>@endif</label>@endforeach</fieldset>
<div><button class="btn btn-sm btn-primary">حفظ (يُعلَّم الدور معدلًا)</button></div></form></section>
@endforeach
<section class="management-panel"><h2 class="h5">دور مخصص جديد</h2><form method="post" action="{{ route('site-admin.roles.store') }}" class="management-grid">@csrf
<label>المفتاح<input class="form-control" name="key" value="{{ old('key') }}" required placeholder="archive-curator">@error('key')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>الاسم<input class="form-control" name="label_ar" value="{{ old('label_ar') }}" required></label>
<label>الوصف<textarea class="form-control" name="description">{{ old('description') }}</textarea></label>
<div><button class="btn btn-primary">إنشاء</button></div></form></section>
@endsection
