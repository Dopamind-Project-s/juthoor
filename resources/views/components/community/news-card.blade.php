@props(['post', 'family'])
@php($date = ($post->publish_at ?? $post->reviewed_at ?? $post->created_at)?->setTimezone(config('community.timezone')))
<article class="content-card news-card {{ $post->isSensitive() ? 'is-sensitive' : '' }}" data-record="news-{{ $post->id }}">
    <div class="d-flex flex-wrap gap-2 align-items-baseline">
        <span class="badge text-bg-light">{{ \App\Models\NewsPost::CATEGORIES[$post->category] ?? $post->category }}</span>
        @if($post->cancelled_at)<span class="badge text-bg-danger" data-cancelled>ملغى</span>@endif
        @if($post->isExpired())<span class="badge text-bg-secondary">انتهى عرضه</span>@endif
        @if($post->notices->isNotEmpty())<span class="badge text-bg-warning">محدّث</span>@endif
        @if($date)<time class="small text-muted" datetime="{{ $date->toIso8601String() }}">{{ $date->format('Y-m-d') }}</time>@endif
    </div>
    <h3 class="h6 fw-bold my-1"><a href="{{ route('community.news.show', [$family->slug, $post->id]) }}">{{ $post->title }}</a></h3>
    @if($post->summary)<p class="small mb-0">{{ $post->summary }}</p>@endif
</article>
