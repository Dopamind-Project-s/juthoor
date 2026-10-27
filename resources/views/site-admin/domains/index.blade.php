@extends('layouts.management')
@section('title','نطاقات العائلات')
@section('page-heading','إدارة الموقع / نطاقات العائلات')
@section('content')
<section class="management-panel"><h1 class="h3">نطاقات مواقع العائلات</h1>
<p class="mb-1">توجيه النطاقات: <strong>{{ $routingEnabled ? 'مفعّل' : 'معطّل' }}</strong> (<code>JUTHOOR_FAMILY_HOSTS</code>). المنصة: <code dir="ltr">{{ config('portals.platform_host') }}</code> · لاحقة النطاقات الفرعية: <code dir="ltr">{{ config('portals.subdomain_suffix') ?? 'غير مضبوطة' }}</code></p>
<p class="text-muted small mb-0">لا يُعدّ نطاق «مفعّلًا» فعليًا إلا بعد ضبط DNS وشهادة TLS والوكيل العكسي وتفعيل التوجيه. التحقق هنا تأكيد إداري موثّق لتلك الفحوص، ولا يجري فحصًا شبكيًا بنفسه.</p></section>
<section class="management-panel"><div class="management-list">@forelse($domains as $domain)<article class="flex-wrap"><div><strong dir="ltr">{{ $domain->host }}</strong><small>{{ $domain->family->name_ar }} · {{ \App\Models\FamilyDomain::STATUSES[$domain->status] }} · {{ $domain->kind === 'custom' ? 'نطاق خاص (TXT: '.$domain->verificationRecord().')' : 'نطاق فرعي' }} · طلبه {{ $domain->requester?->name ?? '—' }}@if($domain->notes) · {{ $domain->notes }}@endif</small></div>
<div class="d-flex flex-wrap gap-2">
@if($domain->status==='pending_verification')<details><summary class="btn btn-sm btn-outline-primary">تحقق</summary><form method="post" action="{{ route('site-admin.domains.transition',[$domain->id,'verify']) }}" class="management-grid mt-2">@csrf
<label><input type="checkbox" name="dns_confirmed" value="1"> سجل DNS يشير إلى خادم المنصة{{ $domain->kind==='custom' ? ' وسجل TXT مطابق' : '' }}</label><label><input type="checkbox" name="tls_confirmed" value="1"> شهادة TLS صالحة للنطاق</label><label><input type="checkbox" name="proxy_confirmed" value="1"> الوكيل العكسي يمرر الطلب بالاسم نفسه</label>
<label>ملاحظات الفحص<textarea name="notes" class="form-control" required maxlength="2000"></textarea></label><button class="btn btn-sm btn-primary">تأكيد التحقق</button></form></details>@endif
@if($domain->status==='verified')<form method="post" action="{{ route('site-admin.domains.transition',[$domain->id,'activate']) }}">@csrf<button class="btn btn-sm btn-primary">تفعيل</button></form>@endif
@if($domain->status!=='disabled')<form method="post" action="{{ route('site-admin.domains.transition',[$domain->id,'disable']) }}">@csrf<button class="btn btn-sm btn-outline-danger">تعطيل</button></form>@else<form method="post" action="{{ route('site-admin.domains.transition',[$domain->id,'reopen']) }}">@csrf<button class="btn btn-sm btn-outline-primary">إعادة فتح للتحقق</button></form>@endif
</div></article>@empty<p class="text-muted">لا توجد نطاقات.</p>@endforelse</div>
@if($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif</section>
<section class="management-panel"><h2 class="h5">تسجيل نطاق لعائلة</h2><form method="post" action="{{ route('site-admin.domains.store') }}" class="d-flex flex-wrap gap-2">@csrf<select class="form-select" style="max-width:260px" name="family_id" aria-label="العائلة">@foreach($families as $option)<option value="{{ $option->id }}">{{ $option->name_ar }}</option>@endforeach</select><input class="form-control" style="max-width:340px" dir="ltr" name="host" placeholder="slug.{{ config('portals.subdomain_suffix') ?? 'example.com' }}" aria-label="النطاق"><button class="btn btn-outline-primary">تسجيل</button></form></section>
@endsection
