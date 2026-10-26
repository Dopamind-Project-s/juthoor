<nav class="d-flex flex-wrap gap-2 mt-2" aria-label="أقسام محتوى الكيان">
    @foreach(\App\Services\Community\CommunityContentModules::MODULES as $key => $info)
        <a class="btn btn-sm {{ ($module ?? null) === $key ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('management.entities.content.index', [$family->slug, $entity->id, $key]) }}" @if(($module ?? null) === $key) aria-current="page" @endif><i class="bi bi-{{ $info['icon'] }}"></i> {{ $info['label'] }}</a>
    @endforeach
</nav>
