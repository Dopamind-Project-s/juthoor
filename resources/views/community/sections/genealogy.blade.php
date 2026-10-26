<section class="entity-section" id="section-genealogy" aria-labelledby="entity-genealogy-title" data-entity-genealogy>
    <h2 class="h5 fw-bold" id="entity-genealogy-title">المؤسس والنسب</h2>
    @if($genealogy && $genealogy['founder'])
        @php($founder = $genealogy['founder'])
        @php($treeSlug = $genealogy['tree']->slug)
        <dl class="entity-facts">
            <div><dt>{{ $genealogy['branch'] ? 'المؤسس' : 'جذر الشجرة المرتبطة' }}</dt><dd><a href="{{ route('genealogy.person', [$family->slug, $founder->id, 'tree' => $treeSlug, 'private' => $private]) }}" data-founder-link>{{ $founder->full_name_ar }}</a></dd></div>
            @if($genealogy['ancestors']->isNotEmpty())
                <div><dt>سلسلة الأسلاف المعروضة</dt><dd class="ancestor-chain" data-ancestor-chain>@foreach($genealogy['ancestors'] as $ancestor)<a href="{{ route('genealogy.person', [$family->slug, $ancestor->id, 'tree' => $treeSlug, 'private' => $private]) }}">{{ $ancestor->first_name_ar ?: $ancestor->full_name_ar }}</a><i class="bi bi-chevron-left" aria-hidden="true"></i>@endforeach<strong>{{ $founder->first_name_ar ?: $founder->full_name_ar }}</strong></dd></div>
            @endif
            <div><dt>الذرية المعروضة في الشجرة</dt><dd>{{ number_format($genealogy['descendantCount']) }} شخصًا</dd></div>
        </dl>
        <div class="mini-tree" data-mini-tree aria-label="شجرة مصغرة لجيلين">
            <div class="mini-tree-root">{{ $founder->full_name_ar }}</div>
            @if($genealogy['children']->isNotEmpty())
                <ul>
                    @foreach($genealogy['children'] as $child)
                        <li><a href="{{ route('genealogy.person', [$family->slug, $child->id, 'tree' => $treeSlug, 'private' => $private]) }}">{{ $child->first_name_ar ?: $child->full_name_ar }}</a>@if($genealogy['grandchildren'][$child->id] ?? 0)<small class="text-muted"> · {{ $genealogy['grandchildren'][$child->id] }} من الأبناء</small>@endif</li>
                    @endforeach
                    @if($genealogy['hiddenChildren'])<li class="text-muted">و{{ $genealogy['hiddenChildren'] }} آخرون في الشجرة الكاملة</li>@endif
                </ul>
            @else
                <p class="small text-muted mb-0">لا أبناء معروضين للمؤسس.</p>
            @endif
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            @if($genealogy['branch'])<a class="btn btn-sm btn-primary" href="{{ route('genealogy.branch', [$family->slug, $genealogy['branch']->slug, 'tree' => $treeSlug, 'private' => $private]) }}"><i class="bi bi-diagram-3"></i> الفرع في الشجرة الكاملة</a>@endif
            <a class="btn btn-sm btn-outline-primary" href="{{ route('genealogy.tree', [$family->slug, 'tree' => $treeSlug, 'private' => $private]) }}">الشجرة الجامعة</a>
        </div>
        @if($genealogy['relatedBranches']->isNotEmpty())
            <h3 class="h6 fw-bold mt-3">فروع نسبية ضمن هذا النسب</h3>
            <ul class="inline-list">@foreach($genealogy['relatedBranches'] as $related)<li><a href="{{ route('genealogy.branch', [$family->slug, $related->slug, 'tree' => $treeSlug, 'private' => $private]) }}">{{ $related->name_ar }}</a></li>@endforeach</ul>
        @endif
        @if($genealogy['sharedAncestors']->isNotEmpty())
            <h3 class="h6 fw-bold mt-3">أجداد مشتركون معتمدون</h3>
            <p class="small mb-0" data-shared-ancestors>يشترك هذا النسب مع: {{ $genealogy['sharedAncestors']->pluck('family')->unique()->implode('، ') }}. <a href="{{ route('shared-ancestry.index') }}">الروابط بين العائلات ومصادرها</a></p>
        @endif
    @else
        <p class="text-muted mb-0" role="status">لا يرتبط هذا الكيان بفرع نسبي منشور. الكيانات الاجتماعية لا تشترط مؤسسًا مثبتًا، ولا يُنشأ مؤسس افتراضي لها.</p>
        @if(($linkedTree = $entity->familyTree) && ($linkedTree->is_active || $preview))<a class="btn btn-sm btn-outline-primary mt-2" href="{{ route('genealogy.tree', [$family->slug, 'tree' => $linkedTree->slug, 'private' => $private]) }}">الشجرة الجامعة</a>@endif
    @endif
    @if($subFounders->isNotEmpty())
        <h3 class="h6 fw-bold mt-3">مؤسسو الكيانات التابعة</h3>
        <ul class="inline-list" data-sub-founders>@foreach($subFounders as $row)<li><a href="{{ route('community.entities.show', [$family->slug, $row['entity']->slug, 'preview' => $private]) }}">{{ $row['entity']->name_ar }}</a>: <a href="{{ route('genealogy.person', [$family->slug, $row['founder']->id, 'tree' => $row['branch']->familyTree?->slug, 'private' => $private]) }}">{{ $row['founder']->full_name_ar }}</a></li>@endforeach</ul>
    @endif
</section>
