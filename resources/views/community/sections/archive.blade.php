<section class="entity-section" id="section-archive" aria-labelledby="entity-archive-title" data-module="archive" data-filter-scope>
    <x-ui.section-head kicker="الوثائق" title="الأرشيف والوثائق" id="entity-archive-title" />
    @if($content['archive']->count() > 1)
    <div class="j-filters" role="search" aria-label="البحث في الأرشيف">
        <label class="j-search"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">ابحث في العناوين والأوصاف</span><input type="search" data-filter-text placeholder="ابحث في الأرشيف"></label>
        <button type="button" class="j-chip" data-filter="" aria-pressed="true">الكل</button>
        @foreach(\App\Models\ArchiveRecord::CATEGORIES as $key => $label)@if($content['archive']->contains('category', $key))<button type="button" class="j-chip" data-filter="{{ $key }}" aria-pressed="false">{{ $label }}</button>@endif @endforeach
        <span class="small text-muted" data-filter-status role="status"></span>
    </div>
    @endif
    <p class="small text-muted">قد تتضمن المعلومات التاريخية روايات متعددة أو سجلات قيد المراجعة؛ تُعرض حالة التحقق والمصادر المتاحة مع كل معلومة بحسب سياسة النشر. رفع المادة لا يعني توثيقها.</p>
    @forelse($content['archive'] as $item)
        <article class="content-card j-card" data-record="archive-{{ $item->id }}" data-filter-item data-filter-tags="{{ $item->category }}">
            <div class="d-flex flex-wrap gap-2 align-items-baseline">
                <h3 class="h6 fw-bold mb-1">{{ $item->title }}</h3>
                <span class="badge text-bg-light">{{ \App\Models\ArchiveRecord::CATEGORIES[$item->category] ?? $item->category }}</span>
                @if($item->period_label || $item->start_year)<span class="small text-muted">{{ $item->period_label }}@if($item->start_year) ({{ $item->start_year }}@if($item->end_year)–{{ $item->end_year }}@endif)@endif</span>@endif
            </div>
            @if($item->description)<div class="entity-description">{!! nl2br(e($item->description)) !!}</div>@endif
            <dl class="entity-facts small mt-2 mb-0">
                @if($item->original_source)<div><dt>المصدر الأصلي</dt><dd>{{ $item->original_source }}</dd></div>@endif
                @if($item->contributor)<div><dt>المساهم</dt><dd>{{ $item->contributor }}</dd></div>@endif
                @if($item->person)<div><dt>الشخص المرتبط</dt><dd>@if(isset($publicPeople[$item->person_id]))<a href="{{ route('genealogy.person', [$family->slug, $item->person_id, 'tree' => $item->person?->familyTree?->slug]) }}">{{ $item->person->full_name_ar }}</a>@else{{ $item->person->full_name_ar }}@endif</dd></div>@endif
                <div><dt>حقوق الاستخدام</dt><dd>{{ \App\Models\ArchiveRecord::RIGHTS[$item->rights_status] ?? $item->rights_status }}@if($item->rights_holder) — {{ $item->rights_holder }}@endif</dd></div>
            </dl>
            <x-community.media-strip :attachments="$item->mediaAttachments" :visible-media="$visibleMedia" />
            <x-community.evidence :record="$item" :entity="$entity" />
        </article>
    @empty
        <x-ui.empty icon="archive" title="لم تُنشر مواد أرشيفية في هذا النطاق بعد.">تُنشر الوثائق والمشجرات والصور التاريخية بعد مراجعة حقوقها ومصادرها.</x-ui.empty>
    @endforelse
</section>
