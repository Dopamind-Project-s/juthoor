<section class="portal-section portal-diwans" id="portal-diwans" aria-labelledby="portal-diwans-title">
    <div class="portal-shell">
        <span class="portal-kicker">المجالس</span><h2 id="portal-diwans-title">الدواوين والمضافات</h2>
        @php($diwans = $content['diwans'] ?? collect())
        @if($diwans->isEmpty())<p class="portal-empty">لا توجد دواوين منشورة بعد.</p>
        @else<div class="portal-card-row">@foreach($diwans->take(6) as $diwan)<article class="portal-card"><i class="bi bi-house-door portal-card-icon" aria-hidden="true"></i><h3>{{ $diwan->name }}</h3>@if($diwan->city || $diwan->governorate)<p class="small"><i class="bi bi-geo-alt"></i> {{ collect([$diwan->city, $diwan->governorate])->filter()->implode('، ') }}</p>@endif @if($diwan->description)<p>{{ \Illuminate\Support\Str::limit($diwan->description, 120) }}</p>@endif</article>@endforeach</div>@endif
    </div>
</section>
