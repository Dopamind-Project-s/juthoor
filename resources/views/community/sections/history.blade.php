@php($narratives = $content['history'])
<section class="entity-section" id="section-history" aria-labelledby="entity-history-title" data-module="history" data-filter-scope>
    <x-ui.section-head kicker="الذاكرة" title="التاريخ والأصول" id="entity-history-title" lead="قد تتضمن المعلومات التاريخية روايات متعددة أو سجلات قيد المراجعة؛ تُعرض حالة التحقق والمصادر المتاحة مع كل معلومة بحسب سياسة النشر." />
    @if($narratives->isNotEmpty())
        @if($narratives->pluck('section')->unique()->count() > 1 || $narratives->contains('account', 'alternative'))
        <div class="j-filters" role="group" aria-label="تصفية الروايات">
            <button type="button" class="j-chip" data-filter="" aria-pressed="true">الكل</button>
            @foreach(\App\Models\EntityNarrative::SECTIONS as $key => $label)@if($narratives->contains('section', $key))<button type="button" class="j-chip" data-filter="{{ $key }}" aria-pressed="false">{{ $label }}</button>@endif @endforeach
            @if($narratives->contains('account', 'alternative'))<button type="button" class="j-chip" data-filter="alternative" aria-pressed="false">الروايات البديلة</button>@endif
            <span class="small text-muted" data-filter-status role="status"></span>
        </div>
        @endif
        {{-- Chronology only from recorded periods and years; undated accounts keep their editorial order. --}}
        <ol class="j-timeline">
        @foreach($narratives->groupBy('section') as $section => $group)
            @foreach($group as $narrative)
            <li class="{{ $narrative->account === 'alternative' ? 'is-alternative' : '' }}" data-filter-item data-filter-tags="{{ $section }} {{ $narrative->account === 'alternative' ? 'alternative' : 'primary' }}">
                <article class="content-card j-article {{ $narrative->account === 'alternative' ? 'is-alternative' : '' }}" data-record="history-{{ $narrative->id }}">
                    <span class="j-period">{{ \App\Models\EntityNarrative::SECTIONS[$section] ?? $section }}@if($narrative->period_label || $narrative->start_year) · {{ $narrative->period_label }}@if($narrative->start_year) ({{ $narrative->start_year }}@if($narrative->end_year)–{{ $narrative->end_year }}@endif)@endif @endif</span>
                    <div class="d-flex flex-wrap gap-2 align-items-baseline">
                        <h3 class="mb-1">{{ $narrative->title }}</h3>
                        @if($narrative->account === 'alternative')<span class="j-pill" data-alternative>رواية بديلة</span>@endif
                    </div>
                    <div class="entity-description j-prose">{!! nl2br(e($narrative->body)) !!}</div>
                    <x-community.media-strip :attachments="$narrative->mediaAttachments" :visible-media="$visibleMedia" />
                    <x-community.evidence :record="$narrative" :entity="$entity" :alternative="$narrative->account === 'alternative' && $narrative->verification_status !== 'verified'" />
                </article>
            </li>
            @endforeach
        @endforeach
        </ol>
    @else
        <x-ui.empty icon="hourglass-split" title="لم تُنشر روايات تاريخية بعد">لهذا {{ $scope === 'direct' ? 'المستوى' : 'المستوى وفروعه' }}. تُنشر الروايات بعد مراجعتها مع مصادرها، والروايات البديلة تبقى منفصلة.</x-ui.empty>
    @endif
</section>
