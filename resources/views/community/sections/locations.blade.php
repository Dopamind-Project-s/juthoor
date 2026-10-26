<section class="entity-section" id="section-locations" aria-labelledby="entity-locations-title" data-module="locations">
    <h2 class="h5 fw-bold" id="entity-locations-title">المناطق والانتشار الجغرافي</h2>
    <p class="small text-muted">مناطق أصل أو استقرار أو هجرة أو تجمع للجماعة، وليست عناوين لأفراد.</p>
    @forelse($content['locations'] as $location)
        <article class="content-card" data-record="locations-{{ $location->id }}">
            <div class="d-flex flex-wrap gap-2 align-items-baseline">
                <h3 class="h6 fw-bold mb-1">{{ $location->name }}</h3>
                <span class="badge text-bg-light">{{ \App\Models\CommunityLocation::KINDS[$location->kind] ?? $location->kind }}</span>
                <span class="badge text-bg-light">{{ \App\Models\CommunityLocation::ERAS[$location->era] ?? $location->era }}</span>
                @if($location->period_label)<span class="small text-muted">{{ $location->period_label }}</span>@endif
            </div>
            @if($location->place_label)<p class="small mb-1"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $location->place_label }}</p>@endif
            @if($location->description)<p class="mb-2">{{ $location->description }}</p>@endif
            <x-community.evidence :record="$location" :entity="$entity" />
        </article>
    @empty
        <p class="text-muted mb-0" role="status">لم تُنشر مناطق مرتبطة بهذا الكيان بعد.</p>
    @endforelse
</section>
