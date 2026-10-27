@extends('layouts.family-site')
@section('nav-current', 'home')
@if($preview)@section('preview-bar')<div class="portal-preview-bar" role="status"><i class="bi bi-eye"></i> معاينة المسودة — لا يراها الزوار حتى النشر. <a href="{{ route('management.portal.edit',$family->slug) }}">العودة إلى الإعدادات</a></div>@endsection @endif
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
@endphp



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


@endsection
