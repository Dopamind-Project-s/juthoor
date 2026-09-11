<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="جذور — منصة رقمية عربية لتوثيق الأنساب وحفظ تاريخ العائلات وربط الأجيال.">
    <title>@yield('title', 'جذور') | Juthoor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="@yield('body-class')">
<a class="visually-hidden-focusable position-absolute bg-white p-3" href="#main-content">انتقل إلى المحتوى</a>
@include('partials.navbar')
<main id="main-content">@yield('content')</main>
@unless(View::hasSection('hide-footer')) @include('partials.footer') @endunless
@include('partials.mobile-navigation')
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body></html>
