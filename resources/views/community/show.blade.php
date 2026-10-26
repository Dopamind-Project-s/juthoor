@extends('layouts.app')
@section('title', $entity->name_ar)
@php
    $private = $preview ? 1 : null;
    $lastReviewed = $entity->reviewed_at;
@endphp
@section('content')
<section class="branch-hero entity-hero">
    <div class="container">
        <x-community.breadcrumb :family="$family" :ancestors="$ancestors" :current="$entity" :preview="$preview" light />
        <div class="d-flex flex-wrap gap-2 mt-3">
            <span class="status-badge light-badge" data-entity-type>{{ $entity->type_label }}</span>
            @if($preview)<span class="status-badge unverified" data-entity-status>معاينة · {{ $entity->status_label }}</span>@endif
        </div>
        <h1>{{ $entity->name_ar }}</h1>
        @if($entity->name_en)<p class="mb-1" lang="en" dir="ltr">{{ $entity->name_en }}</p>@endif
        @if($entity->alternate_names)<p class="mb-1" data-entity-alternates>يُعرف أيضًا بـ: {{ implode('، ', $entity->alternate_names) }}</p>@endif
        @if($entity->summary)<p class="entity-summary">{{ $entity->summary }}</p>@endif
    </div>
</section>
@if($preview)
<div class="container mt-3"><div class="alert alert-warning mb-0" role="status">معاينة لمسؤولي الكيان: قد تظهر هنا صفحات وروابط غير منشورة للعامة. <a href="{{ route('management.entities.edit', [$family->slug, $entity->id]) }}">العودة إلى الإدارة</a></div></div>
@endif
<section class="section-space">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-8">
                <section class="entity-section" aria-labelledby="entity-about">
                    <h2 class="h5 fw-bold" id="entity-about">التعريف</h2>
                    @if($entity->description)
                        <div class="entity-description">{!! nl2br(e($entity->description)) !!}</div>
                    @else
                        <p class="text-muted mb-0">لم تُضف نبذة مفصلة لهذا الكيان بعد.</p>
                    @endif
                </section>

                <section class="entity-section" aria-labelledby="entity-children" data-entity-children>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h2 class="h5 fw-bold mb-0" id="entity-children">الكيانات التابعة <small class="text-muted fw-normal">({{ $directChildCount }} مباشر · {{ $descendantCount }} في كل المستويات)</small></h2>
                        @if($descendantCount > $directChildCount)<x-community.scope-switch :scope="$scope" label="عرض الكيانات التابعة" />@endif
                    </div>
                    @forelse($children as $row)
                        <x-community.entity-card :entity="$row['entity']" :family="$family" :depth="$row['depth']" :preview="$preview" />
                    @empty
                        <p class="text-muted mb-0">لا توجد كيانات تابعة منشورة لهذا المستوى.</p>
                    @endforelse
                </section>

                <section class="entity-section" aria-labelledby="entity-sections">
                    <h2 class="h5 fw-bold" id="entity-sections">أقسام الصفحة</h2>
                    <p class="text-muted small">ستجمع هذه الأقسام محتوى هذا المستوى وفروعه التابعة دون نسخه. لم يُفعّل أي منها بعد.</p>
                    <ul class="entity-planned">
                        @foreach($sections as $key => $section)
                            <li data-planned-section="{{ $key }}"><i class="bi bi-{{ $section['icon'] }}" aria-hidden="true"></i><span>{{ $section['label'] }}</span><span class="badge text-bg-light">غير متاح بعد</span></li>
                        @endforeach
                    </ul>
                </section>
            </div>

            <aside class="col-lg-4 d-grid gap-3 align-content-start">
                <section class="entity-aside" aria-labelledby="entity-hierarchy">
                    <h2 class="h6 fw-bold" id="entity-hierarchy">الموقع في الهرم</h2>
                    <dl class="entity-facts">
                        <div><dt>المستوى</dt><dd>{{ $entity->type_label }}</dd></div>
                        <div><dt>الانتماء الأعلى</dt><dd>@if($ancestors->isNotEmpty())<a href="{{ route('community.entities.show', [$family->slug, $ancestors->last()->slug, 'preview' => $private]) }}">{{ $ancestors->last()->name_ar }}</a>@else أعلى مستوى منشور في {{ $family->name_ar }}@endif</dd></div>
                    </dl>
                </section>

                <section class="entity-aside" aria-labelledby="entity-genealogy" data-entity-genealogy>
                    <h2 class="h6 fw-bold" id="entity-genealogy">الصلة النسبية</h2>
                    @if($founder || $branch || $tree)
                        <dl class="entity-facts">
                            @if($founder)<div><dt>المؤسس</dt><dd><a href="{{ route('genealogy.person', [$family->slug, $founder->id, 'tree' => $branch->familyTree?->slug, 'private' => $private]) }}">{{ $founder->full_name_ar }}</a></dd></div>@endif
                            @if($branch)<div><dt>الفرع النسبي</dt><dd><a href="{{ route('genealogy.branch', [$family->slug, $branch->slug, 'tree' => $branch->familyTree?->slug, 'private' => $private]) }}">{{ $branch->name_ar }}</a></dd></div>@endif
                            @if($tree)<div><dt>الشجرة</dt><dd><a href="{{ route('genealogy.tree', [$family->slug, 'tree' => $tree->slug, 'private' => $private]) }}">{{ $tree->name_ar }}</a></dd></div>@endif
                        </dl>
                        <p class="small text-muted mb-0">يُعرض الأشخاص من الشجرة نفسها دون نسخهم إلى هذه الصفحة.</p>
                    @else
                        <p class="small text-muted mb-0">لا يرتبط هذا الكيان بفرع نسبي منشور. الكيانات الاجتماعية لا تشترط مؤسسًا مثبتًا.</p>
                    @endif
                </section>

                <section class="entity-aside" aria-labelledby="entity-status" data-entity-completeness>
                    <h2 class="h6 fw-bold" id="entity-status">حالة المعلومات</h2>
                    <p class="small mb-2">آخر مراجعة: <strong>{{ $lastReviewed ? $lastReviewed->format('Y-m-d') : 'لم تُراجع بعد' }}</strong></p>
                    <label class="small d-block" for="entity-completeness">اكتمال صفحة التعريف: {{ $completeness['done'] }} من {{ $completeness['total'] }}</label>
                    <progress id="entity-completeness" class="entity-progress" max="{{ $completeness['total'] }}" value="{{ $completeness['done'] }}">{{ $completeness['done'] }}/{{ $completeness['total'] }}</progress>
                    @if($completeness['missing'])<p class="small text-muted mb-2">ينقصها: {{ implode('، ', $completeness['missing']) }}.</p>@endif
                    <p class="small text-muted mb-0">المؤشر يقيس اكتمال التعريف لا صحة المعلومات. لاقتراح تصحيح، تواصل مع إدارة {{ $family->name_ar }} أو قدّم طلب تغيير من حساب عضويتك.</p>
                </section>
            </aside>
        </div>
    </div>
</section>
@endsection
