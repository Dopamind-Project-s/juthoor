<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | {{ $branding['display_name'] }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($branding['intro'] ?: $branding['display_name'], 160) }}">
    @if($preview)<meta name="robots" content="noindex,nofollow">@endif
    <link href="{{ asset('vendor/cairo/cairo.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/juthoor.css') }}" rel="stylesheet">
    <link href="{{ asset('css/design-system.css') }}" rel="stylesheet">
    <link href="{{ asset('css/portal.css') }}" rel="stylesheet">
    {{-- Approved palette values from config only; no administrator-supplied CSS. --}}
    <style>:root{--portal-primary:{{ $palette['primary'] }};--portal-accent:{{ $palette['accent'] }};--portal-soft:{{ $palette['soft'] }}}</style>
</head>
<body class="portal-body">
<a class="visually-hidden-focusable position-absolute bg-white p-3" href="#portal-main">انتقل إلى المحتوى</a>
@if($preview)<div class="portal-preview-bar" role="status"><i class="bi bi-eye"></i> معاينة المسودة — لا يراها الزوار حتى النشر. <a href="{{ route('management.portal.edit',$family->slug) }}">العودة إلى الإعدادات</a></div>@endif
@yield('content')
</body>
</html>
