@extends('layouts.family-site')
@section('nav-current', 'news')
@section('title', 'أخبار '.$entity->name_ar)
@push('styles')<link rel="stylesheet" href="{{ asset('css/community-publications.css') }}">@endpush
@section('content')
<x-page-header :title="'أخبار وإعلانات '.$entity->name_ar" eyebrow="الأخبار والإعلانات" description="الأخبار المنشورة لهذا المستوى وفروعه، والإعلانات الموجهة إليه من المستويات الأعلى.">
    <a class="btn btn-outline-primary mt-3" href="{{ route('community.entities.show', [$family->slug, $entity->slug, 'scope' => $scope]) }}">صفحة {{ $entity->name_ar }}</a>
</x-page-header>
<section class="section-space"><div class="container">
    <form method="get" class="filter-panel d-flex flex-wrap gap-2 align-items-end mb-3" role="search">
        <input type="hidden" name="scope" value="{{ $scope }}">
        <label class="flex-grow-1">بحث<input class="form-control" type="search" name="q" value="{{ $search }}" maxlength="100"></label>
        <label>التصنيف<select class="form-select" name="category"><option value="">الكل</option>@foreach(\App\Models\NewsPost::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>من تاريخ<input class="form-control" type="date" name="from" value="{{ request('from') }}"></label>
        <label>إلى تاريخ<input class="form-control" type="date" name="to" value="{{ request('to') }}"></label>
        <button class="btn btn-primary">تصفية</button>
        <x-community.scope-switch :scope="$scope" label="نطاق الأخبار" />
    </form>
    <nav class="publication-categories d-flex flex-wrap gap-2 mb-3" aria-label="تصنيفات الأخبار">@foreach(\App\Models\NewsPost::CATEGORIES as $key => $label)<a class="btn btn-sm {{ $category === $key ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ request()->fullUrlWithQuery(['category' => $key, 'page' => null]) }}" @if($category === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    @if($featured)<section class="publication-featured mb-4" aria-label="الخبر البارز"><span class="publication-kicker">الخبر البارز</span><x-community.news-card :post="$featured" :family="$family" :visible-media="$visibleMedia" /></section>@endif
    <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h4">آخر الأخبار</h2><span class="text-muted">{{ $posts->total() + ($featured ? 1 : 0) }} خبر</span></div>
    <div class="publication-grid">
    @forelse($posts as $post)
        <x-community.news-card :post="$post" :family="$family" :visible-media="$visibleMedia" />
    @empty
        <p class="text-muted" role="status">لا توجد أخبار مطابقة.</p>
    @endforelse
    </div><div class="mt-3">{{ $posts->links() }}</div>
</div></section>
@endsection
