@props(['event', 'family', 'visibleMedia' => []])
@php($cover = $event->relationLoaded('mediaAttachments') ? $event->mediaAttachments->pluck('media')->first(fn ($media) => $media && $media->isImage() && $media->publication_status === 'published' && isset($visibleMedia[$media->id])) : null)
@php($start = $event->localStart())
<article class="content-card event-card status-{{ $event->display_status }}" data-record="events-{{ $event->id }}">
    <div class="event-date" aria-hidden="true"><strong>{{ $start->format('d') }}</strong><span>{{ $start->locale('ar')->translatedFormat('M Y') }}</span></div>
    <div class="min-w-0">
        @if($cover)<img class="publication-cover" src="{{ $cover->url() }}" alt="{{ $cover->alt_text ?: $cover->title }}" loading="lazy" decoding="async">@endif
        <div class="d-flex flex-wrap gap-2 align-items-baseline">
            <span class="badge text-bg-light">{{ \App\Models\CommunityEvent::CATEGORIES[$event->category] ?? $event->category }}</span>
            <span class="badge {{ ['cancelled' => 'text-bg-danger', 'postponed' => 'text-bg-warning', 'completed' => 'text-bg-secondary'][$event->display_status] ?? 'text-bg-success' }}" data-event-status="{{ $event->display_status }}">{{ \App\Models\CommunityEvent::STATUSES[$event->display_status] }}</span>
        </div>
        @if($event->relationLoaded('notices') && $event->notices->contains('kind', 'rescheduled'))<span class="badge text-bg-info">أعيدت جدولتها</span>@endif
        <h3 class="h6 fw-bold my-1"><a href="{{ route('community.events.show', [$family->slug, $event->id]) }}">{{ $event->title }}</a></h3>
        <p class="small mb-0"><time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->all_day ? $start->format('Y-m-d') : $start->format('Y-m-d H:i') }}</time> ({{ $event->timezone }})@if($event->location_name || $event->place_label) · {{ $event->location_name ?: $event->place_label }}@endif</p>
    </div>
</article>
