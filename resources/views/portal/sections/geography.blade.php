<section class="portal-section portal-geography" id="portal-geography" aria-labelledby="portal-geography-title">
    <div class="portal-shell">
        <span class="portal-kicker">الأرض والانتشار</span><h2 id="portal-geography-title">المناطق والانتشار</h2>
        @php($places = ($content['locations'] ?? collect()))
        @if($places->isEmpty())<x-ui.empty icon="geo-alt" title="لم تُنشر مناطق بعد.">تُعرض هنا المواطن والهجرات ومناطق الوجود العامة بعد اعتمادها مع مصادرها، دون أي عنوان خاص.</x-ui.empty>
        @else
        <div class="j-grid">@foreach($places->groupBy('kind') as $kind => $group)<article class="j-card"><span class="j-entity-type">{{ \App\Models\CommunityLocation::KINDS[$kind] ?? $kind }}</span>
            <ul class="list-unstyled mb-0 mt-2">@foreach($group->take(6) as $place)<li class="mb-1"><strong>{{ $place->name }}</strong>@if($place->period_label)<span class="text-muted small"> · {{ $place->period_label }}</span>@endif</li>@endforeach</ul></article>@endforeach</div>
        <p class="portal-disclaimer">مواقع عامة لوجود العائلة وهجراتها كما توثقها المصادر، وليست عناوين أفراد.</p>
        @if($rootEntity)<a class="portal-more" href="{{ route('community.entities.show', [$family->slug, $rootEntity->slug]) }}#locations">كل المناطق <i class="bi bi-arrow-left"></i></a>@endif
        @endif
    </div>
</section>
