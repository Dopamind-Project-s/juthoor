@php($gallery = $content['media']->filter(fn ($media) => isset($visibleMedia[$media->id])))
<section class="entity-section" id="section-media" aria-labelledby="entity-media-title" data-module="media">
    <h2 class="h5 fw-bold" id="entity-media-title">الصور والوسائط</h2>
    @if($gallery->isNotEmpty())
        <ul class="media-gallery">
            @foreach($gallery as $media)
                <li data-record="media-{{ $media->id }}" data-media="{{ $media->uuid }}">
                    @if($media->isImage())
                        <a href="{{ $media->url() }}" target="_blank" rel="noopener"><img src="{{ $media->url() }}" alt="{{ $media->alt_text ?: $media->title }}" loading="lazy" @if($media->width && $media->height) width="{{ $media->width }}" height="{{ $media->height }}" @endif></a>
                    @elseif($media->category === 'audio')
                        <audio controls preload="none" src="{{ $media->url() }}" aria-label="{{ $media->title }}"></audio>
                    @else
                        <a class="media-document" href="{{ $media->url() }}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> فتح الوثيقة</a>
                    @endif
                    <strong>{{ $media->title }}</strong>
                    <small class="text-muted">{{ \App\Models\MediaItem::CATEGORIES[$media->category] ?? '' }}@if($media->historical_period || $media->historical_year) · {{ $media->historical_period }} {{ $media->historical_year }}@endif · الحقوق: {{ \App\Models\MediaItem::RIGHTS[$media->rights_status] ?? $media->rights_status }}</small>
                    @if($media->description)<p class="small mb-1">{{ $media->description }}</p>@endif
                    <x-community.evidence :record="$media" :entity="$entity" />
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-muted mb-0" role="status">لا توجد صور أو وسائط منشورة في هذا النطاق بعد.</p>
    @endif
</section>
