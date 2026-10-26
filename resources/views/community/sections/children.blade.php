<section class="entity-section" id="section-children" aria-labelledby="entity-children" data-entity-children>
    <h2 class="h5 fw-bold" id="entity-children">الكيانات التابعة <small class="text-muted fw-normal">({{ $directChildCount }} مباشر · {{ $descendantCount }} في كل المستويات)</small></h2>
    @forelse($children as $row)
        <x-community.entity-card :entity="$row['entity']" :family="$family" :depth="$row['depth']" :preview="$preview" />
    @empty
        <p class="text-muted mb-0">لا توجد كيانات تابعة منشورة لهذا المستوى.</p>
    @endforelse
</section>
