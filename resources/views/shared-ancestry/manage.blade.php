@extends('layouts.management')
@section('title','مطابقات العائلات')
@section('content')
<h1>مطابقات العائلات</h1><p>اقتراح مستقل لا يدمج الأشخاص ولا يغير نسب العائلة. تشابه الأسماء وحده ليس دليلًا.</p>
<a class="btn btn-primary mb-3" href="{{ route('cross-links.create',['family'=>$family?->slug]) }}">تقديم اقتراح مع الأدلة</a>
<form class="d-flex gap-2 mb-3"><input type="hidden" name="family" value="{{ $family?->slug }}"><label>حالة المراجعة<select name="status" class="form-select"><option value="">الكل</option>@foreach(['pending_review'=>'قيد المراجعة','approved'=>'معتمد','rejected'=>'مرفوض','disputed'=>'مختلف عليه','needs_review'=>'يحتاج تأكيدًا جديدًا'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></label><button class="btn btn-outline-primary">تصفية</button></form>
@forelse($links as $link)<article class="management-panel mb-3"><a href="{{ route('cross-links.show',$link) }}">اقتراح رقم {{ $link->id }}</a><p>{{ __('shared.status.'.$link->status) }}</p><p>{{ $link->leftFamily->name_ar }} ↔ {{ $link->rightFamily->name_ar }}</p></article>@empty<div class="management-panel">لا توجد اقتراحات ضمن هذا النطاق.</div>@endforelse
{{ $links->links() }}
@endsection
