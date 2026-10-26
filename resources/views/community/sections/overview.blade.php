<section class="entity-section" id="section-overview" aria-labelledby="entity-about">
    <h2 class="h5 fw-bold" id="entity-about">نظرة عامة</h2>
    @if($entity->description)
        <div class="entity-description">{!! nl2br(e($entity->description)) !!}</div>
    @else
        <p class="text-muted mb-0">لم تُضف نبذة مفصلة لهذا الكيان بعد.</p>
    @endif
</section>
