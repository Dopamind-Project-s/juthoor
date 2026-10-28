<section class="portal-section portal-events" id="portal-events" aria-labelledby="portal-events-title">
    <div class="portal-shell">
        <span class="portal-kicker">قريبًا</span><h2 id="portal-events-title">المناسبات القادمة</h2>
        @php($events = $content['events'] ?? collect())
        @if($events->isEmpty())<p class="portal-empty">لا توجد مناسبات قادمة منشورة.</p>
        @else<div class="portal-card-row">@foreach($events->take(3) as $event)<x-community.event-card :event="$event" :family="$family" :visible-media="$visibleMedia ?? []" />@endforeach</div>
        @if($rootEntity)<a class="portal-more" href="{{ route('community.entities.calendar', [$family->slug, $rootEntity->slug]) }}">التقويم <i class="bi bi-arrow-left"></i></a>@endif @endif
    </div>
</section>
