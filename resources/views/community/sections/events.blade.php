<section class="entity-section" id="section-events" aria-labelledby="entity-events-title" data-module="events">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h5 fw-bold mb-0" id="entity-events-title">المناسبات القادمة</h2>
        <a class="btn btn-sm btn-outline-primary" href="{{ route('community.entities.calendar', [$family->slug, $entity->slug, 'scope' => $scope]) }}"><i class="bi bi-calendar3"></i> التقويم والمناسبات السابقة</a>
    </div>
    @forelse($content['events'] as $event)
        <x-community.event-card :event="$event" :family="$family" :visible-media="$visibleMedia ?? []" />
    @empty
        <p class="text-muted mb-0 mt-2" role="status">لا توجد مناسبات قادمة في هذا النطاق.</p>
    @endforelse
</section>
