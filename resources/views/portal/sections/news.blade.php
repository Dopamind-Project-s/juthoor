<section class="portal-section portal-news" id="portal-news" aria-labelledby="portal-news-title">
    <div class="portal-shell">
        <span class="portal-kicker">مستجدات</span><h2 id="portal-news-title">آخر الأخبار</h2>
        @php($posts = $content['news'] ?? collect())
        @if($posts->isEmpty())<p class="portal-empty">لا توجد أخبار منشورة حاليًا.</p>
        @else<div class="portal-card-row">@foreach($posts->take(3) as $post)<x-community.news-card :post="$post" :family="$family" />@endforeach</div>
        @if($rootEntity)<a class="portal-more" href="{{ route('community.entities.news', [$family->slug, $rootEntity->slug]) }}">كل الأخبار <i class="bi bi-arrow-left"></i></a>@endif @endif
    </div>
</section>
