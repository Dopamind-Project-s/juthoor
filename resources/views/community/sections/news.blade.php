<section class="entity-section" id="section-news" aria-labelledby="entity-news-title" data-module="news">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h5 fw-bold mb-0" id="entity-news-title">الأخبار والإعلانات</h2>
        <a class="btn btn-sm btn-outline-primary" href="{{ route('community.entities.news', [$family->slug, $entity->slug, 'scope' => $scope]) }}">كل الأخبار والبحث</a>
    </div>
    @forelse($content['news'] as $post)
        <x-community.news-card :post="$post" :family="$family" />
    @empty
        <p class="text-muted mb-0 mt-2" role="status">لا توجد أخبار منشورة في هذا النطاق.</p>
    @endforelse
</section>
