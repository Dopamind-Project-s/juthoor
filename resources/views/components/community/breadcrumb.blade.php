@props(['family', 'ancestors', 'current' => null, 'light' => false, 'preview' => false])
<nav class="family-breadcrumb entity-breadcrumb {{ $light ? 'light' : '' }}" aria-label="مسار الكيان">
    <a href="{{ route('home') }}">جذور</a><i class="bi bi-chevron-left" aria-hidden="true"></i>
    <a href="{{ route('community.entities.index', $family->slug) }}">{{ $family->name_ar }}</a>
    @foreach($ancestors as $ancestor)
        <i class="bi bi-chevron-left" aria-hidden="true"></i><a href="{{ route('community.entities.show', [$family->slug, $ancestor->slug, 'preview' => $preview ? 1 : null]) }}">{{ $ancestor->name_ar }}</a>
    @endforeach
    @if($current)<i class="bi bi-chevron-left" aria-hidden="true"></i><span aria-current="page">{{ $current->name_ar }}</span>@endif
</nav>
