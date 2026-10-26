<section class="entity-section" id="section-history" aria-labelledby="entity-history-title" data-module="history">
    <h2 class="h5 fw-bold" id="entity-history-title">التاريخ والأصول</h2>
    <p class="small text-muted">قد تتضمن المعلومات التاريخية روايات متعددة أو سجلات قيد المراجعة؛ تُعرض حالة التحقق والمصادر المتاحة مع كل معلومة بحسب سياسة النشر.</p>
    @forelse($content['history']->groupBy('section') as $section => $narratives)
        <h3 class="h6 fw-bold mt-3">{{ \App\Models\EntityNarrative::SECTIONS[$section] ?? $section }}</h3>
        @foreach($narratives as $narrative)
            <article class="content-card {{ $narrative->account === 'alternative' ? 'is-alternative' : '' }}" data-record="history-{{ $narrative->id }}">
                <div class="d-flex flex-wrap gap-2 align-items-baseline">
                    <h4 class="h6 fw-bold mb-1">{{ $narrative->title }}</h4>
                    @if($narrative->account === 'alternative')<span class="badge text-bg-warning" data-alternative>رواية بديلة</span>@endif
                    @if($narrative->period_label || $narrative->start_year)<span class="small text-muted">{{ $narrative->period_label }}@if($narrative->start_year) ({{ $narrative->start_year }}@if($narrative->end_year)–{{ $narrative->end_year }}@endif)@endif</span>@endif
                </div>
                <div class="entity-description">{!! nl2br(e($narrative->body)) !!}</div>
                <x-community.evidence :record="$narrative" :entity="$entity" />
            </article>
        @endforeach
    @empty
        <p class="text-muted mb-0" role="status">لم تُنشر روايات تاريخية لهذا {{ $scope === 'direct' ? 'المستوى' : 'المستوى وفروعه' }} بعد.</p>
    @endforelse
</section>
