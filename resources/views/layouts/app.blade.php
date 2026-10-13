<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="جذور — منصة رقمية عربية لتوثيق الأنساب وحفظ تاريخ العائلات وربط الأجيال.">
    <title>@yield('title', 'جذور') | Juthoor</title>

    <link href="{{ asset('vendor/cairo/cairo.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons/font/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/juthoor.css') }}" rel="stylesheet">
</head>
<body class="@yield('body-class')">
<a class="visually-hidden-focusable position-absolute bg-white p-3" href="#main-content">انتقل إلى المحتوى</a>
@include('partials.navbar')
<main id="main-content">@yield('content')</main>
@unless(View::hasSection('hide-footer')) @include('partials.footer') @endunless
@include('partials.mobile-navigation')
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/juthoor.js') }}"></script>
@stack('scripts')
</body></html>
