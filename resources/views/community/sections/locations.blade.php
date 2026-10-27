@php($locationGroups = $content['locations']->groupBy('kind'))
<section class="entity-section" id="section-locations" aria-labelledby="entity-locations-title" data-module="locations">
    <x-ui.section-head kicker="الجغرافيا" title="المناطق والانتشار الجغرافي" id="entity-locations-title" lead="مناطق أصل أو استقرار أو هجرة أو تجمع للجماعة، وليست عناوين لأفراد." />
    @if($content['locations']->isEmpty())
        <x-ui.empty icon="geo-alt" title="لا توجد مناطق منشورة">لم تُنشر مناطق مرتبطة بهذا الكيان بعد.</x-ui.empty>
    @else
        @foreach(\App\Models\CommunityLocation::KINDS as $kind => $kindLabel)
            @continue(! $locationGroups->has($kind))
            <h3 class="h6 fw-bold mt-3" data-location-kind="{{ $kind }}">{{ $kindLabel }} <span class="j-count">{{ $locationGroups[$kind]->count() }}</span></h3>
            <div class="j-grid">
                @foreach($locationGroups[$kind] as $location)
                    <article class="j-card content-card" data-record="locations-{{ $location->id }}">
                        <h4 class="h6 fw-bold mb-1">{{ $location->name }}</h4>
                        <div class="d-flex flex-wrap gap-1 mb-1">
                            <span class="j-pill">{{ \App\Models\CommunityLocation::ERAS[$location->era] ?? $location->era }}</span>
                            @if($location->period_label)<span class="small text-muted">{{ $location->period_label }}</span>@endif
                        </div>
                        @if($location->place_label)<p class="small mb-1"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $location->place_label }}</p>@endif
                        @if($location->description)<p class="mb-2 small">{{ $location->description }}</p>@endif
                        <x-community.evidence :record="$location" :entity="$entity" />
                    </article>
                @endforeach
            </div>
        @endforeach
    @endif
</section>
