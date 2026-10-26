<section class="entity-section" id="section-diwans" aria-labelledby="entity-diwans-title" data-module="diwans">
    <h2 class="h5 fw-bold" id="entity-diwans-title">الدواوين والمضافات</h2>
    <div class="row g-3">
        @forelse($content['diwans'] as $diwan)
            <div class="col-md-6">
                <article class="content-card h-100" data-record="diwans-{{ $diwan->id }}">
                    <div class="d-flex flex-wrap gap-2 align-items-baseline"><h3 class="h6 fw-bold mb-1">{{ $diwan->name }}</h3><span class="badge text-bg-light">{{ \App\Models\Diwan::KINDS[$diwan->kind] ?? $diwan->kind }}</span></div>
                    @if($diwan->place_label)<p class="small mb-1"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $diwan->place_label }}@if($diwan->public_address) — {{ $diwan->public_address }}@endif</p>@endif
                    @if($diwan->description)<p class="mb-2">{{ $diwan->description }}</p>@endif
                    @if($diwan->visit_instructions)<p class="small mb-2"><strong>تعليمات الزيارة:</strong> {{ $diwan->visit_instructions }}</p>@endif
                    @if($diwan->contact_consent && ($diwan->contact_name || $diwan->contact_phone))<p class="small mb-2" data-diwan-contact><strong>للتواصل:</strong> {{ $diwan->contact_name }} @if($diwan->contact_phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $diwan->contact_phone) }}" dir="ltr">{{ $diwan->contact_phone }}</a>@endif</p>@endif
                    @if($embed = $diwan->embedUrl())<iframe class="diwan-map" src="{{ $embed }}" title="خريطة {{ $diwan->name }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>@endif
                    @if($directions = $diwan->directionsUrl())<a class="btn btn-sm btn-primary mb-2" href="{{ $directions }}" target="_blank" rel="noopener" data-directions><i class="bi bi-sign-turn-right"></i> فتح الاتجاهات في Google Maps</a>@endif
                    <x-community.evidence :record="$diwan" :entity="$entity" />
                </article>
            </div>
        @empty
            <div class="col-12"><p class="text-muted mb-0" role="status">لم تُنشر دواوين أو مضافات لهذا الكيان بعد.</p></div>
        @endforelse
    </div>
</section>
