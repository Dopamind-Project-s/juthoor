@php($gallery = $content['media']->filter(fn ($media) => isset($visibleMedia[$media->id])))
<section class="entity-section" id="section-media" aria-labelledby="entity-media-title" data-module="media" data-filter-scope>
    <x-ui.section-head kicker="المعرض" title="الصور والوسائط" id="entity-media-title" lead="تُعرض الوسائط المنشورة والمسموح بها فقط، عبر مسار الوسائط الآمن." />
    @if($gallery->isEmpty())
        <x-ui.empty icon="images" title="لا توجد وسائط منشورة">لا توجد صور أو وسائط منشورة في هذا النطاق بعد.</x-ui.empty>
    @else
        @if($gallery->pluck('category')->unique()->count() > 1)
            <div class="j-filters" role="group" aria-label="تصفية حسب النوع">
                <button type="button" class="j-chip" data-filter="" aria-pressed="true">الكل</button>
                @foreach($gallery->pluck('category')->unique() as $mediaCategory)<button type="button" class="j-chip" data-filter="{{ $mediaCategory }}" aria-pressed="false">{{ \App\Models\MediaItem::CATEGORIES[$mediaCategory] ?? $mediaCategory }}</button>@endforeach
                <span class="small text-muted" data-filter-status role="status"></span>
            </div>
        @endif
        <ul class="j-gallery media-gallery list-unstyled">
            @foreach($gallery as $media)
                <li data-record="media-{{ $media->id }}" data-media="{{ $media->uuid }}" data-filter-item data-filter-tags="{{ $media->category }}">
                    <figure>
                        @if($media->isImage())
                            <a href="{{ $media->url() }}" target="_blank" rel="noopener"><img src="{{ $media->url() }}" alt="{{ $media->alt_text ?: $media->title }}" loading="lazy" @if($media->width && $media->height) width="{{ $media->width }}" height="{{ $media->height }}" @endif></a>
                        @elseif($media->category === 'audio')
                            <div class="p-2"><audio controls preload="none" src="{{ $media->url() }}" aria-label="{{ $media->title }}" class="w-100"></audio></div>
                        @else
                            <a class="j-file media-document p-2" href="{{ $media->url() }}" target="_blank" rel="noopener"><span class="j-file-icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span> فتح الوثيقة</a>
                        @endif
                        <figcaption>
                            <strong class="d-block">{{ $media->title }}</strong>
                            <small class="text-muted">{{ \App\Models\MediaItem::CATEGORIES[$media->category] ?? '' }}@if($media->historical_period || $media->historical_year) · {{ $media->historical_period }} {{ $media->historical_year }}@endif · الحقوق: {{ \App\Models\MediaItem::RIGHTS[$media->rights_status] ?? $media->rights_status }}</small>
                            @if($media->description)<p class="small mb-1">{{ $media->description }}</p>@endif
                            <x-community.evidence :record="$media" :entity="$entity" />
                        </figcaption>
                    </figure>
                </li>
            @endforeach
        </ul>
    @endif
</section>
