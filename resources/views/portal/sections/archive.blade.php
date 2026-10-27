<section class="portal-section portal-archive" id="portal-archive" aria-labelledby="portal-archive-title">
    <div class="portal-shell">
        <span class="portal-kicker">الوثائق</span><h2 id="portal-archive-title">من الأرشيف</h2>
        @php($records = $content['archive'] ?? collect())
        @if($records->isEmpty())<p class="portal-empty">لا توجد وثائق منشورة بعد.</p>
        @else<div class="portal-card-row">@foreach($records->take(6) as $record)
            @php($image = $record->relationLoaded('mediaAttachments') ? $record->mediaAttachments->pluck('media')->first(fn ($media) => $media && isset($visibleMedia[$media->id]) && $media->isImage()) : null)
            <article class="portal-card portal-archive-card">@if($image)<img src="{{ route('media.show', [$image->uuid, 'display']) }}" alt="{{ $image->alt_text ?: $record->title }}" loading="lazy">@endif<h3>{{ $record->title }}</h3>@if($record->start_year)<p class="small text-muted">{{ $record->start_year }}</p>@endif</article>
        @endforeach</div>@endif
    </div>
</section>
