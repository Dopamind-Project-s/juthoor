@extends('layouts.management')
@section('page-heading','المصادر والاستشهادات')
@section('content')
<section class="management-panel"><h1 class="h4">مكتبة مصادر {{ $family->name_ar }}</h1><p>المصادر مشتركة داخل العائلة، والاستشهادات مرتبطة بالشجرة المختارة. الملفات خاصة وتنزيلها متاح لأعضاء العائلة فقط.</p>
@can('manageSources',$family)<details class="management-form" id="new-source" @if(request()->boolean('new')) open @endif><summary>إضافة مصدر</summary><form method="post" enctype="multipart/form-data" action="{{ route('management.sources.store',[$family->slug,'tree'=>$tree->slug]) }}" class="management-grid" data-source-form>@csrf @include('management.sources.fields',['source'=>null])<button class="btn btn-primary">حفظ المصدر</button></form></details>@endcan
<div class="management-list">@forelse($sources as $source)<article><div><strong>{{ $source->title }}</strong><small>{{ $source->reference }}</small></div><a href="{{ route('management.sources.show',[$family->slug,$source->id,'tree'=>$tree->slug]) }}" class="btn btn-outline-primary">تفاصيل واستشهادات</a></article>@empty<p>لا توجد مصادر بعد.</p>@endforelse</div>{{ $sources->links() }}</section>
@endsection
