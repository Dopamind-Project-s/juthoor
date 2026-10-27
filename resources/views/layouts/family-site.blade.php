{{-- Family website frame: landing page, entity pages, hierarchy directory and heritage pages share it. Chrome comes
     from App\Services\Portal\FamilySiteChrome (view composer); only approved palette values reach the CSS. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | {{ $branding['display_name'] }}</title>
    <meta name="description" content="@yield('description', \Illuminate\Support\Str::limit($branding['intro'] ?: $branding['display_name'], 160))">
    @if(!empty($preview))<meta name="robots" content="noindex,nofollow">@endif
    <link href="{{ asset('vendor/cairo/cairo.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/juthoor.css') }}" rel="stylesheet">
    <link href="{{ asset('css/design-system.css') }}" rel="stylesheet">
    <link href="{{ asset('css/portal.css') }}" rel="stylesheet">
    <style>:root{--j-brand:{{ $palette['primary'] }};--j-brand-2:color-mix(in srgb,{{ $palette['primary'] }} 78%,#fff);--j-accent:{{ $palette['accent'] }};--j-tint:{{ $palette['soft'] }}}</style>
@stack('styles')</head>
<body class="portal-body @yield('body-class')">
<a class="visually-hidden-focusable position-absolute bg-white p-3" href="#site-main">انتقل إلى المحتوى</a>
@yield('preview-bar')
<x-family-site.header :family="$family" :branding="$branding" :logo="$logo" :portal-url="$portalUrl" :nav="$siteNav" :current="$__env->yieldContent('nav-current')" />
<main id="site-main">@yield('content')</main>
<x-family-site.footer :family="$family" :branding="$branding" :nav="$siteNav" :on-family-host="$onFamilyHost" />
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/juthoor.js') }}"></script>
@stack('scripts')
</body>
</html>
