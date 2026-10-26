@extends('layouts.app')
@section('title', $entity->name_ar)
@php
    $private = $preview ? 1 : null;
    $counts = [
        'history' => $content['history']->count(), 'locations' => $content['locations']->count(), 'diwans' => $content['diwans']->count(),
        'achievements' => $content['achievements']->count(), 'sources' => $sources->count(), 'children' => $children->count(),
        'news' => $content['news']->count(), 'events' => $content['events']->count(),
        'archive' => $content['archive']->count(), 'media' => $content['media']->filter(fn ($media) => isset($visibleMedia[$media->id]))->count(),
    ];
@endphp
@section('content')
<section class="branch-hero entity-hero {{ $cover ? 'has-cover' : '' }}" @if($cover) style="--entity-cover: url('{{ $cover->url() }}')" data-entity-cover @endif>
    <div class="container">
        <x-community.breadcrumb :family="$family" :ancestors="$ancestors" :current="$entity" :preview="$preview" light />
        <div class="d-flex flex-wrap gap-2 mt-3">
            <span class="status-badge light-badge" data-entity-type>{{ $entity->type_label }}</span>
            @if($preview)<span class="status-badge unverified" data-entity-status>معاينة · {{ $entity->status_label }}</span>@endif
        </div>
        <h1>{{ $entity->name_ar }}</h1>
        @if($entity->name_en)<p class="mb-1" lang="en" dir="ltr">{{ $entity->name_en }}</p>@endif
        @if($entity->alternate_names)<p class="mb-1" data-entity-alternates>يُعرف أيضًا بـ: {{ implode('، ', $entity->alternate_names) }}</p>@endif
        @if($entity->summary)<p class="entity-summary">{{ $entity->summary }}</p>@endif
    </div>
</section>
@if($preview)
<div class="container mt-3"><div class="alert alert-warning mb-0" role="status">معاينة لمسؤولي الكيان: قد تظهر هنا صفحات وروابط غير منشورة للعامة. المحتوى يظهر بعد نشره فقط. <a href="{{ route('management.entities.edit', [$family->slug, $entity->id]) }}">العودة إلى الإدارة</a></div></div>
@endif
<nav class="entity-section-nav" aria-label="أقسام الصفحة">
    <div class="container d-flex flex-wrap align-items-center gap-2">
        <ul class="entity-section-links">
            @foreach($sections as $key => $section)
                <li><a href="#section-{{ $key }}"><i class="bi bi-{{ $section['icon'] }}" aria-hidden="true"></i> {{ $section['label'] }}@isset($counts[$key])<span class="badge text-bg-light ms-1">{{ $counts[$key] }}</span>@endisset</a></li>
            @endforeach
        </ul>
        <x-community.scope-switch :scope="$scope" label="نطاق المحتوى في كل الأقسام" />
    </div>
</nav>
<div class="container entity-portal" data-entity-scope="{{ $scope }}">
    <p class="small text-muted mt-3 mb-0" role="status">{{ $scope === \App\Services\Community\EntityContentScope::DIRECT ? 'تعرض الأقسام محتوى هذا المستوى فقط.' : 'تعرض الأقسام محتوى هذا المستوى وفروعه التابعة، والمحتوى الموجه إليه من المستويات الأعلى.' }}</p>
    <div class="row g-4 py-4">
        <div class="col-lg-8 d-grid gap-4 align-content-start">
            @include('community.sections.overview')
            @include('community.sections.news')
            @include('community.sections.events')
            @include('community.sections.history')
            @include('community.sections.archive')
            @include('community.sections.locations')
            @include('community.sections.genealogy')
            @include('community.sections.statistics')
            @include('community.sections.diwans')
            @include('community.sections.achievements')
            @include('community.sections.media')
            @include('community.sections.sources')
            @include('community.sections.children')
        </div>
        <aside class="col-lg-4 d-grid gap-3 align-content-start">
            <section class="entity-aside" aria-labelledby="entity-hierarchy">
                <h2 class="h6 fw-bold" id="entity-hierarchy">الموقع في الهرم</h2>
                <dl class="entity-facts">
                    <div><dt>المستوى</dt><dd>{{ $entity->type_label }}</dd></div>
                    <div><dt>الانتماء الأعلى</dt><dd>@if($ancestors->isNotEmpty())<a href="{{ route('community.entities.show', [$family->slug, $ancestors->last()->slug, 'preview' => $private]) }}">{{ $ancestors->last()->name_ar }}</a>@else أعلى مستوى منشور في {{ $family->name_ar }}@endif</dd></div>
                </dl>
            </section>
            @include('community.sections.review')
        </aside>
    </div>
</div>
@endsection
