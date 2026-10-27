@extends('layouts.family-site')
@section('nav-current', 'news')
@section('title', 'أخبار '.$entity->name_ar)
@section('content')
<x-page-header :title="'أخبار وإعلانات '.$entity->name_ar" eyebrow="الأخبار والإعلانات" description="الأخبار المنشورة لهذا المستوى وفروعه، والإعلانات الموجهة إليه من المستويات الأعلى.">
    <a class="btn btn-outline-primary mt-3" href="{{ route('community.entities.show', [$family->slug, $entity->slug, 'scope' => $scope]) }}">صفحة {{ $entity->name_ar }}</a>
</x-page-header>
<section class="section-space"><div class="container">
    <form method="get" class="filter-panel d-flex flex-wrap gap-2 align-items-end mb-3" role="search">
        <input type="hidden" name="scope" value="{{ $scope }}">
        <label class="flex-grow-1">بحث<input class="form-control" type="search" name="q" value="{{ $search }}" maxlength="100"></label>
        <label>التصنيف<select class="form-select" name="category"><option value="">الكل</option>@foreach(\App\Models\NewsPost::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>@endforeach</select></label>
        <button class="btn btn-primary">تصفية</button>
        <x-community.scope-switch :scope="$scope" label="نطاق الأخبار" />
    </form>
    @forelse($posts as $post)
        <x-community.news-card :post="$post" :family="$family" />
    @empty
        <p class="text-muted" role="status">لا توجد أخبار مطابقة.</p>
    @endforelse
    <div class="mt-3">{{ $posts->links() }}</div>
</div></section>
@endsection
