<section class="entity-section" id="section-sources" aria-labelledby="entity-sources-title" data-sources>
    <h2 class="h5 fw-bold" id="entity-sources-title">المصادر</h2>
    @forelse($sources as $source)
        @if($loop->first)<ul class="content-citations">@endif
        <li><i class="bi bi-journal-text" aria-hidden="true"></i> {{ $source->title }}@if($source->url) — <a href="{{ $source->url }}" rel="noopener nofollow" target="_blank">رابط المصدر</a>@endif</li>
        @if($loop->last)</ul>@endif
    @empty
        <p class="text-muted mb-0" role="status">لا توجد مصادر معتمدة لمحتوى معروض في هذا النطاق.</p>
    @endforelse
</section>
