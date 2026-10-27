<section class="entity-section" id="section-sources" aria-labelledby="entity-sources-title" data-sources>
    <x-ui.section-head kicker="التوثيق" title="المصادر" id="entity-sources-title" lead="المصادر المعتمدة للمحتوى المعروض في هذا النطاق فقط." />
    @if($sources->isEmpty())
        <x-ui.empty icon="journal-text" title="لا توجد مصادر">لا توجد مصادر معتمدة لمحتوى معروض في هذا النطاق.</x-ui.empty>
    @else
        <ol class="j-sources content-citations">
            @foreach($sources as $source)
                <li data-source="{{ $source->id }}"><i class="bi bi-journal-text" aria-hidden="true"></i> <span>{{ $source->title }}@if($source->url) — <a href="{{ $source->url }}" rel="noopener nofollow" target="_blank">رابط المصدر</a>@endif</span></li>
            @endforeach
        </ol>
    @endif
</section>
