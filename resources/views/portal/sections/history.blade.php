<section class="portal-section portal-history" id="portal-history" aria-labelledby="portal-history-title">
    <div class="portal-shell">
        <span class="portal-kicker">الذاكرة</span><h2 id="portal-history-title">التاريخ والأصول</h2>
        @php($narratives = $content['history'] ?? collect())
        @if($narratives->isEmpty())<p class="portal-empty">لم يُنشر سرد تاريخي معتمد بعد. الروايات تُنشر بعد مراجعتها مع مصادرها.</p>
        @else<div class="portal-timeline">@foreach($narratives->take(4) as $narrative)<article class="portal-timeline-item">
            @if($narrative->period_label || $narrative->start_year)<span class="portal-timeline-period">{{ $narrative->period_label ?: $narrative->start_year }}</span>@endif
            <h3>{{ $narrative->title }}</h3><p>{{ \Illuminate\Support\Str::limit(strip_tags($narrative->body), 260) }}</p>
            @if($narrative->account !== 'primary')<span class="badge text-bg-light">رواية بديلة</span>@endif
        </article>@endforeach</div>
        @if($rootEntity)<a class="portal-more" href="{{ route('community.entities.show', [$family->slug, $rootEntity->slug]) }}#history">التاريخ كاملًا مع المصادر <i class="bi bi-arrow-left"></i></a>@endif @endif
    </div>
</section>
