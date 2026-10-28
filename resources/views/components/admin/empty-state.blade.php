@props(['title' => 'لا توجد سجلات مطابقة', 'description' => null])
<div {{ $attributes->class(['admin-empty-state']) }} role="status"><i class="bi bi-inbox" aria-hidden="true"></i><strong>{{ $title }}</strong>@if($description)<p>{{ $description }}</p>@endif{{ $slot }}</div>
