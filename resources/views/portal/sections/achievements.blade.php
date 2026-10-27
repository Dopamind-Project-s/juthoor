<section class="portal-section portal-achievements" id="portal-achievements" aria-labelledby="portal-achievements-title">
    <div class="portal-shell">
        <span class="portal-kicker">أعلام</span><h2 id="portal-achievements-title">شخصيات وإنجازات</h2>
        @php($achievements = $content['achievements'] ?? collect())
        @if($achievements->isEmpty())<p class="portal-empty">لا توجد إنجازات معتمدة منشورة بعد.</p>
        @else<ul class="portal-list">@foreach($achievements->take(8) as $achievement)<li><strong>{{ $achievement->title }}</strong>@if($achievement->person && isset($publicPeople[$achievement->person->id])) — <a href="{{ route('genealogy.person', [$family->slug, $achievement->person->id]) }}">{{ $achievement->person->full_name_ar }}</a>@endif @if($achievement->period_label)<span class="text-muted small"> · {{ $achievement->period_label }}</span>@endif</li>@endforeach</ul>
        <p class="portal-disclaimer">تُعرض الإنجازات الموثقة بمصدر؛ الترتيب أبجدي ولا يعني تفضيلًا.</p>@endif
    </div>
</section>
