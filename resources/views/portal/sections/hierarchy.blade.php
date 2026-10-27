<section class="portal-section portal-hierarchy" id="portal-hierarchy" aria-labelledby="portal-hierarchy-title">
    <div class="portal-shell">
        <span class="portal-kicker">البنية</span><h2 id="portal-hierarchy-title">الأفخاذ والفروع</h2>
        @if($hierarchy['items'] === [])
            <p class="portal-empty">لم تُنشر أفخاذ أو فروع بعد. تظهر هنا عند اعتمادها ونشرها.</p>
        @else
            <ol class="portal-hierarchy-list">
                @foreach($hierarchy['items'] as $item)
                <li class="portal-node depth-{{ min($item['depth'], 3) }}" data-node="{{ $item['key'] }}">
                    <a href="{{ $item['url'] }}" class="portal-node-card">
                        <span class="portal-node-type">{{ $item['type'] }}@if($item['parent']) · تابع لـ {{ $item['parent'] }}@endif</span>
                        <strong>{{ $item['name'] }}</strong>
                        @if($item['founder'])<span class="portal-node-founder"><i class="bi bi-person"></i> المؤسس: {{ $item['founder']->full_name_ar }}</span>@endif
                        @if($item['people'] !== null)<span class="portal-node-count">السجلات المنشورة: {{ number_format($item['people']) }}</span>@endif
                        @if($item['summary'])<span class="portal-node-summary">{{ \Illuminate\Support\Str::limit($item['summary'], 140) }}</span>@endif
                    </a>
                </li>
                @endforeach
            </ol>
            <a class="portal-more" href="{{ route('community.entities.index', $family->slug) }}">كل الكيانات <i class="bi bi-arrow-left"></i></a>
        @endif
    </div>
</section>
