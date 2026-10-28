@props(['title', 'description' => null, 'eyebrow' => 'مساحة العمل'])
<header {{ $attributes->class(['admin-page-header']) }}><div><span class="eyebrow">{{ $eyebrow }}</span><h1>{{ $title }}</h1>@if($description)<p>{{ $description }}</p>@endif</div>@if($slot->isNotEmpty())<div class="admin-header-actions">{{ $slot }}</div>@endif</header>
