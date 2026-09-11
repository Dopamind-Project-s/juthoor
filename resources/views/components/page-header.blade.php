@props(['title','description'=>null,'eyebrow'=>null])
<section class="page-header"><div class="container">@if($eyebrow)<span class="eyebrow">{{ $eyebrow }}</span>@endif<h1 class="section-title mb-2">{{ $title }}</h1>@if($description)<p class="section-lead mb-0">{{ $description }}</p>@endif{{ $slot }}</div></section>
