@props(['groups'])
<nav class="workspace-nav" aria-label="أقسام مساحة العمل">
@foreach($groups as $label => $links)
<section class="admin-nav-group" aria-label="{{ $label }}"><h2 class="workspace-nav-heading">{{ $label }}</h2>
@foreach($links as $link)
@php($active = url()->current() === strtok($link['url'], '?') && ! str_contains($link['url'], '#'))
<a href="{{ $link['url'] }}" @if($active) aria-current="page" @endif><i class="bi bi-{{ $link['icon'] }}" aria-hidden="true"></i><span>{{ $link['label'] }}</span></a>
@endforeach</section>
@endforeach
</nav>
