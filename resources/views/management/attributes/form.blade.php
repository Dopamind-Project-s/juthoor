@extends('layouts.management')
@section('title','إدارة صفة')
@section('page-heading',$attribute ? 'تعديل الصفة' : 'إضافة صفة')
@section('content')
@php
$prefix = $family ? 'management.attributes.' : 'site-admin.attributes.';
$params = $family ? [$family->slug] : [];
@endphp
<section class="management-panel">
<h1 class="h3">{{ $attribute ? 'تعديل الصفة' : 'إضافة صفة' }}</h1>
<form method="post" class="management-grid" action="{{ route($prefix.($attribute ? 'update' : 'store'),$attribute ? [...$params,$attribute->id] : $params) }}">
@csrf @if($attribute) @method('PUT') @endif
<label>الاسم المعروض<input name="label" class="form-control" value="{{ old('label',$attribute?->label) }}" placeholder="اسم الصفة بالعربية" required maxlength="255">@error('label')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>لون الصفة<input type="color" name="color" class="form-control form-control-color" value="{{ old('color',$attribute?->color ?? config('person_attributes.default_color')) }}" required>@error('color')<span class="field-error">{{ $message }}</span>@enderror</label>
<label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$attribute?->is_active ?? true))> صفة نشطة</label>
<button class="btn btn-primary">حفظ</button><a href="{{ route($prefix.'index',$params) }}" class="btn btn-outline-primary">العودة للقائمة</a>
</form></section>
@endsection
