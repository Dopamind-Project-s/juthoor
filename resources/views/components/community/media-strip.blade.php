@props(['attachments', 'visibleMedia'])
@php($shown = $attachments->filter(fn ($attachment) => $attachment->media && isset($visibleMedia[$attachment->media->id])))
@if($shown->isNotEmpty())
    <ul class="media-strip" data-media-strip>
        @foreach($shown as $attachment)
            @php($media = $attachment->media)
            <li data-media="{{ $media->uuid }}">
                @if($media->isImage())
                    <a href="{{ $media->url() }}" target="_blank" rel="noopener"><img src="{{ $media->url() }}" alt="{{ $media->alt_text ?: $media->title }}" loading="lazy" @if($media->width && $media->height) width="{{ $media->width }}" height="{{ $media->height }}" @endif></a>
                @elseif($media->category === 'audio')
                    <audio controls preload="none" src="{{ $media->url() }}" aria-label="{{ $media->title }}"></audio>
                @else
                    <a class="media-document" href="{{ $media->url() }}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> {{ $media->title }}</a>
                @endif
                @if($attachment->caption || $media->title)<small>{{ $attachment->caption ?: $media->title }}</small>@endif
            </li>
        @endforeach
    </ul>
@endif
