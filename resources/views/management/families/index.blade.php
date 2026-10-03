@extends('layouts.management')
@section('breadcrumb','مساحة الإدارة')
@section('page-heading','عائلاتي')
@section('title','عائلاتي')
@section('content')
<section><div><div class="row g-4">@forelse($families as $family)<div class="col-md-6"><a class="product-card card-body d-block" href="{{ route('management.families.show', $family->slug) }}"><span class="status-badge">{{ auth()->user()->isSuperAdmin() ? 'سوبر أدمن' : ['reader'=>'قارئ','editor'=>'محرر','admin'=>'مدير'][$family->pivot->role] }}</span><h2 class="h4 mt-3">{{ $family->name_ar }}</h2><p class="text-muted">{{ $family->short_description }}</p></a></div>@empty<div class="empty-state"><h2>لا توجد عضويات</h2><p>اطلب من مدير العائلة إضافتك.</p></div>@endforelse</div>{{ $families->links() }}</div></section>
@endsection
