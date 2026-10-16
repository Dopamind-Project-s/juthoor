@extends('layouts.management')
@section('title','صفات الأشخاص')
@section('page-heading','صفات الأشخاص')
@section('content')
@php
$prefix = $family ? 'management.attributes.' : 'site-admin.attributes.';
$params = $family ? [$family->slug] : [];
@endphp
<div class="d-flex justify-content-between mb-4"><h1 class="h3">صفات الأشخاص</h1><a class="btn btn-primary" href="{{ route($prefix.'create',$params) }}">إضافة صفة</a></div>
<p>الصفات المشتركة يديرها السوبر أدمن. يستطيع مدير العائلة إدارة صفات عائلته، وتبقى الصفات المخفية محفوظة في السجل.</p>
<section class="management-panel">
<form method="get" class="management-grid"><label>بحث<input name="q" class="form-control" value="{{ request('q') }}" placeholder="اسم الصفة"></label><label>الحالة<select name="active" class="form-select"><option value="">الكل</option><option value="1" @selected(request('active')==='1')>نشطة</option><option value="0" @selected(request('active')==='0')>معطلة</option></select></label><button class="btn btn-primary">بحث وتصفية</button></form>
<div class="management-list">
@forelse($attributes as $attribute)
<article><div><span class="person-attribute" style="--attribute-color:{{ $attribute->color }};--attribute-ink:{{ $attribute->text_color }}">{{ $attribute->label }}</span><small>{{ $attribute->family_id ? 'صفة خاصة بالعائلة' : 'صفة مشتركة' }} · {{ $attribute->is_active ? 'نشطة' : 'معطلة' }} · <bdi>{{ $attribute->color }}</bdi></small></div>
@if($attribute->family_id === $family?->id)<a class="btn btn-outline-primary" href="{{ route($prefix.'edit',[...$params,$attribute->id]) }}">تعديل اللون والحالة</a>@else<span class="text-muted">يديرها السوبر أدمن</span>@endif
</article>
@empty<p class="text-muted py-4">لا توجد صفات مطابقة.</p>@endforelse
</div>{{ $attributes->links() }}</section>
@endsection
