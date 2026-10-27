@extends('layouts.portal')
@section('title', 'الصفحة الرئيسية')
@section('content')
@php
    $treeUrl = $tree ? route('genealogy.tree', [$family->slug, 'tree' => $tree->slug]) : null;
    $ctaUrl = match ($branding['cta_target']) {
        'entities' => '#portal-hierarchy',
        'directory' => route('directory.index', $family->slug),
        'contribute' => '#portal-contribute',
        default => $treeUrl ?? '#portal-hierarchy',
    };
    $nav = collect(['hierarchy' => 'الأفخاذ', 'genealogy' => 'النسب', 'history' => 'التاريخ', 'diwans' => 'الدواوين', 'news' => 'الأخبار', 'directory' => 'الدليل'])
        ->filter(fn ($label, $key) => in_array($key, $sections, true));
    $monogram = mb_substr(preg_replace(['/^(عشيرة|عائلة|آل|قبيلة)\s+/u', '/^ال/u'], '', $branding['display_name']), 0, 1);
@endphp
<header class="portal-header" id="portal-top">
    <div class="portal-shell portal-header-inner">
        <a class="portal-brand" href="{{ $portalUrl }}">
            @if($logo)<img src="{{ route('media.show', [$logo->uuid, 'display']) }}" alt="{{ $logo->alt_text ?: 'شعار '.$branding['display_name'] }}" width="48" height="48">@else<span class="portal-monogram" aria-hidden="true">{{ $monogram }}</span>@endif
            <span><strong>{{ $branding['display_name'] }}</strong><small>على منصة جذور</small></span>
        </a>
        @php($links = collect($nav)->map(fn ($label, $key) => '<a href="#portal-'.e($key).'">'.e($label).'</a>')->implode('').($treeUrl ? '<a class="portal-nav-cta" href="'.e($treeUrl).'"><i class="bi bi-diagram-3"></i> الشجرة</a>' : ''))
        <nav class="portal-nav portal-nav-desktop" aria-label="أقسام موقع العائلة">{!! $links !!}</nav>
        <details class="portal-menu"><summary aria-label="القائمة"><i class="bi bi-list"></i></summary><nav class="portal-nav" aria-label="أقسام موقع العائلة (الجوال)">{!! $links !!}</nav></details>
    </div>
</header>

<main id="portal-main">
<section class="portal-hero{{ $cover ? ' has-cover' : '' }}" aria-labelledby="portal-hero-title">
    @if($cover)<img class="portal-hero-image" src="{{ route('media.show', [$cover->uuid, 'display']) }}" alt="{{ $cover->alt_text ?: $cover->title }}">@endif
    <div class="portal-hero-ornament" aria-hidden="true"></div>
    <div class="portal-shell portal-hero-inner">
        <span class="portal-eyebrow">{{ $branding['display_name'] }}</span>
        <h1 id="portal-hero-title">{{ $branding['hero_title'] }}</h1>
        @if($branding['hero_subtitle'])<p class="portal-hero-subtitle">{{ $branding['hero_subtitle'] }}</p>@endif
        @if($branding['intro'])<p class="portal-hero-intro">{{ $branding['intro'] }}</p>@endif
        @if(in_array('search', $sections, true) && $treeUrl)
        <form class="portal-search" method="get" action="{{ $treeUrl }}" role="search">
            <input type="hidden" name="tree" value="{{ $tree->slug }}">
            <label class="visually-hidden" for="portal-search-input">ابحث عن شخص في الشجرة</label>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input id="portal-search-input" name="q" type="search" minlength="2" maxlength="80" placeholder="ابحث باسم الجد أو الشخص…" autocomplete="off">
            <button class="btn">بحث</button>
        </form>
        @endif
        <div class="portal-hero-actions"><a class="btn portal-btn-primary" href="{{ $ctaUrl }}">{{ $branding['cta_label'] }}</a>@if($treeUrl && $branding['cta_target'] !== 'tree')<a class="btn portal-btn-ghost" href="{{ $treeUrl }}">الشجرة الجامعة</a>@endif</div>
        @if($cover && $cover->title)<p class="portal-hero-credit"><i class="bi bi-image"></i> {{ $cover->title }}</p>@endif
    </div>
</section>

@foreach($sections as $section)
    @includeIf('portal.sections.'.$section)
@endforeach
</main>

<footer class="portal-footer">
    <div class="portal-shell portal-footer-grid">
        <div><strong class="portal-footer-name">{{ $branding['display_name'] }}</strong>
            @if($branding['footer_text'])<p>{{ $branding['footer_text'] }}</p>@endif
            <p class="small">السجلات المنشورة تخضع لمراجعة العائلة؛ وجود سجل لا يعني توثيقه تاريخيًا، وغياب شخص لا يعني عدم انتمائه.</p></div>
        <div>@if($branding['contact_links'] || $branding['public_email'])<h2 class="h6">تواصل</h2><ul class="list-unstyled">@foreach($branding['contact_links'] as $link)<li><a href="{{ $link['url'] }}" rel="noopener nofollow" target="_blank">{{ $link['label'] }}</a></li>@endforeach @if($branding['public_email'])<li><span dir="ltr">{{ $branding['public_email'] }}</span></li>@endif</ul>@endif</div>
        <div><h2 class="h6">روابط</h2><ul class="list-unstyled">@if($treeUrl)<li><a href="{{ $treeUrl }}">الشجرة الجامعة</a></li>@endif<li><a href="{{ route('community.entities.index', $family->slug) }}">الأفخاذ والكيانات</a></li><li><a href="{{ route('directory.index', $family->slug) }}">دليل الأعضاء</a></li></ul></div>
    </div>
    <div class="portal-shell portal-platform-credit">موقع العائلة على <a href="{{ $onFamilyHost ? app(\App\Services\Portal\FamilyHosts::class)->origin(config('portals.platform_host')).'/' : route('home') }}">منصة جذور</a> لتوثيق الأنساب.</div>
</footer>
@endsection
