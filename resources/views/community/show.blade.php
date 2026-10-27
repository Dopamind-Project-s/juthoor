@extends('layouts.family-site')
@section('title', $entity->name_ar)
@section('description', \Illuminate\Support\Str::limit($entity->summary ?: $entity->name_ar, 160))
@section('nav-current', 'entities')
@php
    $private = $preview ? 1 : null;
    $visibleGallery = $content['media']->filter(fn ($media) => isset($visibleMedia[$media->id]));
    $counts = [
        'history' => $content['history']->count(), 'locations' => $content['locations']->count(), 'diwans' => $content['diwans']->count(),
        'achievements' => $content['achievements']->count(), 'sources' => $sources->count(), 'children' => $children->count(),
        'news' => $content['news']->count(), 'events' => $content['events']->count(),
        'archive' => $content['archive']->count(), 'media' => $visibleGallery->count(),
    ];
    // One navigation model for every entity level; each tab groups existing sections and keeps their anchors.
    $tabs = [
        'overview' => ['label' => 'نظرة عامة', 'icon' => 'info-circle', 'sections' => ['overview', 'statistics', 'children'], 'count' => null, 'aliases' => 'overview statistics children'],
        'tree' => ['label' => 'الشجرة', 'icon' => 'diagram-3', 'sections' => ['genealogy'], 'count' => null, 'aliases' => 'genealogy tree'],
        'history' => ['label' => 'التاريخ', 'icon' => 'hourglass-split', 'sections' => ['history'], 'count' => $counts['history'], 'aliases' => 'history'],
        'places' => ['label' => 'الأصول والمناطق', 'icon' => 'geo-alt', 'sections' => ['locations'], 'count' => $counts['locations'], 'aliases' => 'locations places'],
        'diwans' => ['label' => 'الدواوين', 'icon' => 'house-door', 'sections' => ['diwans'], 'count' => $counts['diwans'], 'aliases' => 'diwans'],
        'people' => ['label' => 'الشخصيات', 'icon' => 'person-badge', 'sections' => ['achievements'], 'count' => $counts['achievements'], 'aliases' => 'achievements people'],
        'archive' => ['label' => 'الأرشيف والمعرض', 'icon' => 'archive', 'sections' => ['archive', 'media'], 'count' => $counts['archive'] + $counts['media'], 'aliases' => 'archive media gallery'],
        'news' => ['label' => 'الأخبار والمناسبات', 'icon' => 'megaphone', 'sections' => ['news', 'events'], 'count' => $counts['news'] + $counts['events'], 'aliases' => 'news events'],
        'sources' => ['label' => 'المصادر', 'icon' => 'journal-text', 'sections' => ['sources', 'review'], 'count' => $counts['sources'], 'aliases' => 'sources review'],
    ];
    $founder = $genealogy['founder'] ?? null;
    $treeSlug = $genealogy['tree']->slug ?? null;
    $branchTreeUrl = ($genealogy['branch'] ?? null) ? route('genealogy.branch', [$family->slug, $genealogy['branch']->slug, 'tree' => $treeSlug, 'private' => $private]) : null;
    $embedUrl = match (true) {
        $branchTreeUrl !== null => route('genealogy.branch', [$family->slug, $genealogy['branch']->slug, 'tree' => $treeSlug, 'private' => $private, 'embed' => 1]),
        $founder !== null => route('genealogy.tree', [$family->slug, 'tree' => $treeSlug, 'private' => $private, 'embed' => 1, 'person' => $founder->id]),
        default => null,
    };
@endphp
@if($preview)
@section('preview-bar')<div class="portal-preview-bar" role="status"><i class="bi bi-eye"></i> معاينة لمسؤولي الكيان: قد تظهر هنا صفحات غير منشورة للعامة؛ المحتوى يظهر بعد نشره فقط. <a href="{{ route('management.entities.edit', [$family->slug, $entity->id]) }}">العودة إلى الإدارة</a></div>@endsection
@endif
@section('content')
<section class="j-hero entity-hero {{ $cover ? 'has-cover' : '' }}" aria-labelledby="entity-title" @if($cover) data-entity-cover @endif>
    @if($cover)<img class="j-hero-cover" src="{{ $cover->url() }}" alt="{{ $cover->alt_text ?: $entity->name_ar }}">@endif
    <div class="j-shell">
        <x-community.breadcrumb :family="$family" :ancestors="$ancestors" :current="$entity" :preview="$preview" light />
        <div class="d-flex flex-wrap gap-2 mt-2">
            <span class="j-pill on-dark" data-entity-type>{{ $entity->type_label }}</span>
            @if($preview)<span class="j-pill" data-entity-status>معاينة · {{ $entity->status_label }}</span>@endif
        </div>
        <h1 id="entity-title">{{ $entity->name_ar }}</h1>
        @if($entity->name_en)<p class="mb-1" lang="en" dir="ltr">{{ $entity->name_en }}</p>@endif
        @if($entity->alternate_names)<p class="mb-1" data-entity-alternates>يُعرف أيضًا بـ: {{ implode('، ', $entity->alternate_names) }}</p>@endif
        @if($entity->summary)<p class="j-hero-lead entity-summary">{{ $entity->summary }}</p>@endif
        <div class="j-hero-meta">
            @if($founder)<span><i class="bi bi-star-fill"></i> {{ $genealogy['branch'] ? 'المؤسس' : 'جذر الشجرة' }}: {{ $founder->full_name_ar }}</span>@endif
            @if($statistics['anchored'])<span><i class="bi bi-people"></i> سجلات منشورة: {{ number_format($statistics['published']) }}</span>@endif
            @if($descendantCount)<span><i class="bi bi-diagram-2"></i> كيانات تابعة: {{ $descendantCount }}</span>@endif
        </div>
        <div class="j-hero-actions">
            @if($branchTreeUrl)<a class="j-btn j-btn-accent" href="{{ $branchTreeUrl }}"><i class="bi bi-diagram-3"></i> شجرة {{ $entity->type_label }}</a>@endif
            @if($descendantCount)<a class="j-btn j-btn-on-dark" href="#children"><i class="bi bi-diagram-2"></i> الفروع التابعة</a>@endif
            <x-community.scope-switch :scope="$scope" label="نطاق المحتوى في كل الأقسام" />
        </div>
    </div>
</section>

<nav class="j-tabs entity-section-nav" aria-label="أقسام الصفحة">
    <div class="j-shell">
        <div role="tablist" aria-label="أقسام {{ $entity->name_ar }}" data-tabs>
            @foreach($tabs as $key => $tab)
                <a class="j-tab {{ $tab['count'] === 0 ? 'is-empty' : '' }}" role="tab" id="tab-{{ $key }}" href="#{{ $key }}" aria-controls="panel-{{ $key }}" data-tab="{{ $key }}"><i class="bi bi-{{ $tab['icon'] }}" aria-hidden="true"></i> {{ $tab['label'] }}@if($tab['count'] !== null)<span class="j-count">{{ $tab['count'] }}</span>@endif</a>
            @endforeach
        </div>
    </div>
</nav>

<div class="j-shell entity-portal" data-entity-scope="{{ $scope }}">
    <p class="small text-muted mt-3 mb-0" role="status">{{ $scope === \App\Services\Community\EntityContentScope::DIRECT ? 'تعرض الأقسام محتوى هذا المستوى فقط.' : 'تعرض الأقسام محتوى هذا المستوى وفروعه التابعة، والمحتوى الموجه إليه من المستويات الأعلى.' }}</p>
    @foreach($tabs as $key => $tab)
        <div class="j-tabpanel" role="tabpanel" id="panel-{{ $key }}" aria-labelledby="tab-{{ $key }}" data-panel="{{ $key }}" data-aliases="{{ $tab['aliases'] }}" tabindex="-1">
            @if($key === 'overview')
                <div class="entity-overview-grid">
                    <div class="d-grid gap-4 align-content-start">@include('community.sections.overview')
                        @if($founder && $genealogy['branch'])<x-ui.founder-card :founder="$founder" :family="$family" :tree="$genealogy['tree']" :branch="$genealogy['branch']" :classification="'مؤسس '.$genealogy['branch']->type_label" :tree-url="$branchTreeUrl" :private="$preview" />@endif
                    </div>
                    <aside class="d-grid gap-3 align-content-start">
                        <section class="j-card entity-aside" aria-labelledby="entity-hierarchy">
                            <h2 class="h6 fw-bold" id="entity-hierarchy">الموقع في الهرم</h2>
                            <dl class="entity-facts mb-0">
                                <div><dt>المستوى</dt><dd>{{ $entity->type_label }}</dd></div>
                                <div><dt>الانتماء الأعلى</dt><dd>@if($ancestors->isNotEmpty())<a href="{{ route('community.entities.show', [$family->slug, $ancestors->last()->slug, 'preview' => $private]) }}">{{ $ancestors->last()->name_ar }}</a>@else أعلى مستوى منشور @endif</dd></div>
                                <div><dt>الكيانات التابعة</dt><dd>{{ $directChildCount }} مباشر · {{ $descendantCount }} في كل المستويات</dd></div>
                            </dl>
                        </section>
                    </aside>
                </div>
                @include('community.sections.statistics')
                <div id="children">@include('community.sections.children')</div>
            @elseif($key === 'tree')
                @include('community.sections.genealogy')
                @if($embedUrl)
                <section class="j-embed" aria-label="الشجرة المركزة" data-tree-embed>
                    <div class="j-embed-bar"><strong>{{ $genealogy['branch'] ? 'شجرة '.$entity->name_ar : 'الشجرة الجامعة' }}</strong><span class="small text-muted">نفس الشجرة الجامعة؛ السجلات المنشورة فقط.</span><a class="j-link" href="{{ str_replace(['&embed=1', '?embed=1'], ['', '?'], $embedUrl) }}" target="_top"><i class="bi bi-arrows-fullscreen"></i> فتح بملء الصفحة</a></div>
                    {{-- The frame is created when this tab opens, so the graph loads only on demand and never twice. --}}
                    <div class="j-embed-frame" data-embed-src="{{ $embedUrl }}" data-embed-title="الشجرة المركزة لـ{{ $entity->name_ar }}"><p class="p-3 mb-0"><a href="{{ $embedUrl }}">فتح الشجرة المركزة</a></p></div>
                </section>
                @endif
            @else
                @foreach($tab['sections'] as $section)@include('community.sections.'.$section)@endforeach
            @endif
        </div>
    @endforeach
</div>
@endsection
@push('scripts')<script src="{{ asset('js/entity-portal.js') }}" defer></script>@endpush
