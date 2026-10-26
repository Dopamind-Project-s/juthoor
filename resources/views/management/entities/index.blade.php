@extends('layouts.management')
@section('page-heading','صفحات الكيانات')
@section('title','صفحات الكيانات')
@section('content')
<section class="management-panel">
    <span class="eyebrow">القبيلة والعشيرة والأفخاذ</span>
    <h1 class="h4">صفحات الكيانات المجتمعية</h1>
    <p class="text-muted">كل كيان صفحة مستقلة ضمن هرم مرن: قبيلة ← عشيرة ← فخذ ← فخذ فرعي ← فرع / حمولة، ويمكن تخطي المستويات غير المستخدمة. ربط الكيان بفرع نسبي اختياري ولا يُنشئ أشخاصًا أو جذورًا جديدة. تظهر الصفحة للعامة عندما تُنشر هي وكل المستويات الأعلى منها.</p>
    <dl class="reconciliation-summary">
        @foreach(\App\Models\CommunityEntity::STATUSES as $status => $label)
            <div><dt>{{ $label }}</dt><dd>{{ $counts[$status] ?? 0 }}</dd></div>
        @endforeach
    </dl>
    <div class="d-flex flex-wrap gap-2">
        @if($canCreateRoot)<a class="btn btn-primary" href="{{ route('management.entities.create', $family->slug) }}"><i class="bi bi-plus-lg"></i> كيان في أعلى الهرم</a>@endif
        <a class="btn btn-outline-primary" href="{{ route('community.entities.index', $family->slug) }}"><i class="bi bi-box-arrow-up-left"></i> الصفحات العامة</a>
    </div>
</section>
<section class="management-panel">
    <h2 class="h5">الهرم</h2>
    <div class="management-list entity-admin-list">
        @forelse($rows as $row)
            @php($entity = $row['entity'])
            @php($canManage = $manageable === null || in_array($entity->id, $manageable, true))
            <article style="--entity-depth: {{ min($row['depth'], 6) }}" data-admin-entity="{{ $entity->slug }}">
                <div>
                    <strong>{{ $entity->name_ar }}</strong>
                    <small>{{ $entity->type_label }} · {{ $entity->status_label }}@if(in_array($entity->id, $publicIds, true)) · ظاهرة للعامة@elseif($entity->publication_status === \App\Models\CommunityEntity::STATUS_PUBLISHED) · مخفية لأن مستوى أعلى غير منشور@endif @if($entity->branch) · الفرع: {{ $entity->branch->name_ar }}@endif @if($entity->managers_count) · {{ $entity->managers_count }} مسؤول@endif</small>
                </div>
                @if($canManage)
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-primary" href="{{ route('management.entities.edit', [$family->slug, $entity->id]) }}">إدارة</a>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('management.entities.create', [$family->slug, 'parent' => $entity->id]) }}">كيان تابع</a>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('community.entities.show', [$family->slug, $entity->slug, 'preview' => in_array($entity->id, $publicIds, true) ? null : 1]) }}">الصفحة</a>
                    </div>
                @endif
            </article>
        @empty
            <p class="text-muted py-3" role="status">لا توجد كيانات بعد. ابدأ بالعشيرة أو القبيلة، ثم أضف الأفخاذ والفروع التابعة.</p>
        @endforelse
    </div>
</section>
@endsection
