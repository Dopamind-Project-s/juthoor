@props(['entity', 'family', 'depth' => 0, 'preview' => false])
<article class="entity-card" style="--entity-depth: {{ min($depth, 6) }}" data-entity="{{ $entity->slug }}">
    <span class="branch-mark" aria-hidden="true"><i class="bi bi-diagram-2"></i></span>
    <div>
        <span class="status-badge">{{ $entity->type_label }}</span>
        @if($preview && $entity->publication_status !== \App\Models\CommunityEntity::STATUS_PUBLISHED)<span class="status-badge unverified">{{ $entity->status_label }}</span>@endif
        <h3 class="h6 fw-bold mt-2 mb-1"><a href="{{ route('community.entities.show', [$family->slug, $entity->slug, 'preview' => $preview ? 1 : null]) }}">{{ $entity->name_ar }}</a></h3>
        @if($entity->summary)<p class="text-muted small mb-0">{{ $entity->summary }}</p>@endif
    </div>
</article>
