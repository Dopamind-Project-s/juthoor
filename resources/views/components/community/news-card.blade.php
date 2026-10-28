@props(['post', 'family', 'visibleMedia' => []])
@php($cover = $post->relationLoaded('mediaAttachments') ? $post->mediaAttachments->pluck('media')->first(fn ($media) => $media && $media->isImage() && $media->publication_status === 'published' && isset($visibleMedia[$media->id])) : null)
@php($date = ($post->publish_at ?? $post->reviewed_at ?? $post->created_at)?->setTimezone(config('community.timezone')))
<article class="content-card news-card {{ $post->isSensitive() ? 'is-sensitive' : '' }} {{ in_array($post->category, ['congratulation', 'graduation', 'wedding', 'achievement', 'appointment', 'award'], true) ? 'is-celebration' : '' }}" data-record="news-{{ $post->id }}">
    @if($cover)<img class="publication-cover" src="{{ $cover->url() }}" alt="{{ $cover->alt_text ?: $cover->title }}" loading="lazy" decoding="async">@endif
    <div class="d-flex flex-wrap gap-2 align-items-baseline">
        <span class="badge text-bg-light">{{ \App\Models\NewsPost::CATEGORIES[$post->category] ?? $post->category }}</span>
        @if($post->cancelled_at)<span class="badge text-bg-danger" data-cancelled>ملغى</span>@endif
        @if($post->isExpired())<span class="badge text-bg-secondary">انتهى عرضه</span>@endif
        @if($post->notices->isNotEmpty())<span class="badge text-bg-warning">محدّث</span>@endif
        @if($date)<time class="small text-muted" datetime="{{ $date->toIso8601String() }}">{{ $date->format('Y-m-d') }}</time>@endif
    </div>
    <h3 class="h5 fw-bold my-1"><a href="{{ route('community.news.show', [$family->slug, $post->id]) }}">{{ $post->title }}</a></h3>
    @if($post->summary)<p class="small mb-0">{{ $post->summary }}</p>@endif
<p class="small text-muted mt-2 mb-0">{{ $post->ownerEntity?->name_ar }}</p>
</article>
