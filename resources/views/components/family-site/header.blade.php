@props(['family', 'branding', 'logo' => null, 'portalUrl', 'nav' => [], 'current' => ''])
@php
    $monogram = mb_substr(preg_replace(['/^(عشيرة|عائلة|آل|قبيلة)\s+/u', '/^ال/u'], '', $branding['display_name']), 0, 1);
    $links = collect($nav)->map(fn ($item, $key) => '<a href="'.e($item['url']).'"'.($key === $current ? ' aria-current="page"' : '').($key === 'tree' ? ' class="portal-nav-cta"' : '').'>'.($key === 'tree' ? '<i class="bi bi-diagram-3"></i> ' : '').e($item['label']).'</a>')->implode('');
@endphp
<header class="portal-header" id="portal-top">
    <div class="portal-shell portal-header-inner">
        <a class="portal-brand" href="{{ $portalUrl }}">
            @if($logo)<img src="{{ route('media.show', [$logo->uuid, 'display']) }}" alt="{{ $logo->alt_text ?: 'شعار '.$branding['display_name'] }}" width="48" height="48">@else<span class="portal-monogram" aria-hidden="true">{{ $monogram }}</span>@endif
            <span><strong>{{ $branding['display_name'] }}</strong><small>على منصة جذور</small></span>
        </a>
        <nav class="portal-nav portal-nav-desktop" aria-label="تنقل موقع العائلة">{!! $links !!}</nav>
        <details class="portal-menu"><summary aria-label="القائمة"><i class="bi bi-list"></i></summary><nav class="portal-nav" aria-label="تنقل موقع العائلة (الجوال)">{!! $links !!}</nav></details>
    </div>
</header>
