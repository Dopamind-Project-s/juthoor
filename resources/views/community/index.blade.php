@extends('layouts.family-site')
@section('title', 'الأفخاذ والفروع')
@section('nav-current', 'entities')
@section('content')
@php
    $levels = collect($items)->countBy(fn ($item) => $item['entity']->type_label);
@endphp
<section class="j-hero" aria-labelledby="directory-title">
    <div class="j-shell">
        <ol class="j-breadcrumb" aria-label="مسار التنقل"><li><a href="{{ $portalUrl }}">{{ $branding['display_name'] }}</a></li><li aria-current="page">الأفخاذ والفروع</li></ol>
        <span class="j-pill on-dark">الهرم الاجتماعي والإداري</span>
        <h1 id="directory-title">القبائل والعشائر والأفخاذ</h1>
        <p class="j-hero-lead">صفحات تعريفية لمستويات {{ $branding['display_name'] }} كما تنشرها إدارتها. الانتساب النسبي يُعرض في الشجرة الجامعة الموثقة؛ أعداد السجلات المنشورة ليست تعدادًا للسكان.</p>
        @if($total)<dl class="j-stats on-dark mt-4" style="max-width:720px">
            <div class="j-stat"><dt>صفحات منشورة</dt><dd data-directory-total>{{ $total }}</dd></div>
            @foreach($levels->take(3) as $label => $count)<div class="j-stat"><dt>{{ $label }}</dt><dd>{{ $count }}</dd></div>@endforeach
        </dl>@endif
        <div class="j-hero-actions">@if($siteTree)<a class="j-btn j-btn-accent" href="{{ route('genealogy.tree', [$family->slug, 'tree' => $siteTree->slug]) }}"><i class="bi bi-diagram-3"></i> الشجرة الجامعة</a>@endif<a class="j-btn j-btn-on-dark" href="{{ route('directory.index', $family->slug) }}"><i class="bi bi-person-vcard"></i> دليل الأعضاء</a></div>
    </div>
</section>

<section class="j-section">
    <div class="j-shell">
        @if($total === 0)
            <x-ui.empty icon="diagram-2" title="لا توجد صفحات منشورة بعد">لم تنشر إدارة العائلة صفحات للقبيلة أو العشيرة أو الأفخاذ. تبقى الشجرة الجامعة متاحة للسجلات المنشورة.</x-ui.empty>
        @else
        <form class="j-filters" method="get" role="search" aria-label="البحث في الأفخاذ والفروع">
            <label class="j-search"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">ابحث باسم الفرع أو المؤسس</span><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ابحث باسم الفرع أو المؤسس" maxlength="80"></label>
            <label class="visually-hidden" for="directory-type">المستوى</label>
            <select class="form-select w-auto" id="directory-type" name="type"><option value="">كل المستويات</option>@foreach($types as $value => $label)<option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select>
            <label class="visually-hidden" for="directory-within">داخل</label>
            <select class="form-select w-auto" id="directory-within" name="within"><option value="">كل الهرم</option>@foreach($roots as $root)<option value="{{ $root->id }}" @selected((int) ($filters['within'] ?? 0) === $root->id)>داخل {{ $root->name_ar }}</option>@endforeach</select>
            <button class="j-btn j-btn-primary">عرض</button>
            @if($filtered)<a class="j-link" href="{{ route('community.entities.index', $family->slug) }}">مسح البحث</a>@endif
        </form>
        <p class="small text-muted" role="status" data-directory-count>{{ $filtered ? 'نتائج البحث: '.count($items).' من '.$total : 'كل الصفحات المنشورة: '.$total }}</p>

        @if($items === [])
            <x-ui.empty icon="search" title="لا توجد نتائج مطابقة">جرّب اسمًا آخر أو مستوى مختلفًا. يُبحث في أسماء الصفحات وأسماء المؤسسين المنشورين.</x-ui.empty>
        @else
        <div class="j-directory">
            @foreach($items as $item)
                @php($entity = $item['entity'])
                <article class="j-card j-entity-card {{ $item['depth'] === 0 && ! $filtered ? 'is-root' : '' }}" data-entity="{{ $entity->slug }}" data-depth="{{ $item['depth'] }}" style="--depth: {{ min($item['depth'], 4) }}">
                    @if($item['cover'])<div class="j-card-media"><img src="{{ route('media.show', [$item['cover']->uuid, 'display']) }}" alt="{{ $item['cover']->alt_text ?: $entity->name_ar }}" loading="lazy" width="480" height="270"></div>@endif
                    <span class="j-entity-type">{{ $entity->type_label }}</span>
                    @if($item['path'])<span class="j-entity-parent"> · {{ implode(' ‹ ', array_reverse($item['path'])) }}</span>@endif
                    <h2 class="h5 fw-bold mt-1 mb-1"><a class="stretched-link text-reset" href="{{ route('community.entities.show', [$family->slug, $entity->slug]) }}">{{ $entity->name_ar }}</a></h2>
                    @if($entity->summary)<p class="text-muted small mb-2">{{ \Illuminate\Support\Str::limit($entity->summary, 160) }}</p>@endif
                    <div class="j-card-meta">
                        @if($item['founder'])<span data-founder><i class="bi bi-star"></i> المؤسس: {{ $item['founder']->full_name_ar }}</span>@endif
                        @if($item['people'] !== null)<span><i class="bi bi-people"></i> سجلات منشورة: {{ number_format($item['people']) }}</span>@endif
                        @if($item['descendants'])<span><i class="bi bi-diagram-2"></i> فروع تابعة: {{ $item['descendants'] }}</span>@endif
                    </div>
                    @if($item['treeUrl'])<div class="j-card-foot position-relative" style="z-index:2"><a class="j-link" href="{{ $item['treeUrl'] }}"><i class="bi bi-diagram-3"></i> شجرة الفرع</a></div>@endif
                </article>
            @endforeach
        </div>
        @endif
        @endif
    </div>
</section>
@endsection
