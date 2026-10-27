@props(['icon' => 'inbox', 'title'])
<div {{ $attributes->merge(['class' => 'j-empty', 'role' => 'status']) }}><i class="bi bi-{{ $icon }}" aria-hidden="true"></i><div><strong>{{ $title }}</strong>{{ $slot }}</div></div>
