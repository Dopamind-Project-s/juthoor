<section class="portal-section portal-gallery" id="portal-gallery" aria-labelledby="portal-gallery-title">
    <div class="portal-shell">
        <span class="portal-kicker">صور وذاكرة</span><h2 id="portal-gallery-title">معرض الصور</h2>
        @php($images = ($content['media'] ?? collect())->filter(fn ($media) => isset($visibleMedia[$media->id]) && $media->isImage())->take(8))
        @if($images->isEmpty())<x-ui.empty icon="images" title="لا توجد صور منشورة بعد.">تُنشر الصور بعد مراجعة حقوقها، وتُعرض نسخ خالية من البيانات الوصفية.</x-ui.empty>
        @else
        <div class="j-gallery">@foreach($images as $image)<figure data-media="{{ $image->id }}"><img src="{{ route('media.show', [$image->uuid, 'display']) }}" alt="{{ $image->alt_text ?: $image->title }}" loading="lazy" width="320" height="240"><figcaption>{{ $image->title }}@if($image->historical_year)<span class="text-muted"> · {{ $image->historical_year }}</span>@endif</figcaption></figure>@endforeach</div>
        @if($rootEntity)<a class="portal-more" href="{{ route('community.entities.show', [$family->slug, $rootEntity->slug]) }}#archive">الأرشيف والمعرض كاملًا <i class="bi bi-arrow-left"></i></a>@endif
        @endif
    </div>
</section>
