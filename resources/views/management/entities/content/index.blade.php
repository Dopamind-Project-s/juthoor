@extends('layouts.management')
@php($moduleInfo = \App\Services\Community\CommunityContentModules::MODULES[$module])
@section('page-heading', $moduleInfo['label'])
@section('title', $moduleInfo['label'].' — '.$entity->name_ar)
@section('content')
<section class="management-panel">
    <span class="eyebrow"><a href="{{ auth()->user()->can('update', $entity) ? route('management.entities.edit', [$family->slug, $entity->id]) : route('management.entities.index', $family->slug) }}">{{ $entity->name_ar }}</a></span>
    <p class="small text-muted">المالك: {{ $entity->name_ar }} · النشر يحتاج مراجعة مخولة، والظهور في كيانات أخرى لا ينقل حق التحرير.</p>
    <h1 class="h4"><i class="bi bi-{{ $moduleInfo['icon'] }}"></i> {{ $moduleInfo['label'] }}</h1>
    @include('management.entities.content.module-nav')
    <form method="get" class="management-grid mt-3" role="search"><label>بحث في العنوان<input name="q" class="form-control" type="search" maxlength="100" value="{{ request('q') }}"></label><label>حالة النشر<select name="status" class="form-select"><option value="">كل الحالات</option>@foreach(\App\Models\EntityNarrative::PUBLICATION_STATUSES as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></label><button class="btn btn-outline-primary">تصفية</button></form>
    <a class="btn btn-primary mt-3" href="{{ route('management.entities.content.create', [$family->slug, $entity->id, $module]) }}"><i class="bi bi-plus-lg"></i> سجل جديد</a>
</section>
<section class="management-panel">
    <h2 class="h5">سجلات يملكها هذا الكيان</h2>
    <div class="management-list">
        @forelse($records as $record)
            <article data-content-record="{{ $record->id }}">
                <div><strong>{{ $record->content_title }}</strong><small>{{ $record->status_label }} · {{ $record->verification_label }} · {{ \App\Models\EntityNarrative::AUDIENCES[$record->audience] ?? $record->audience }} · {{ $record->citations_count }} استشهاد</small></div>
                <a class="btn btn-sm btn-primary" href="{{ route('management.entities.content.edit', [$family->slug, $entity->id, $module, $record->id]) }}">إدارة</a>
            </article>
        @empty
            <p class="text-muted py-3" role="status">لا توجد سجلات بعد.</p>
        @endforelse
    </div>
    @if($linked->isNotEmpty())
        <h2 class="h6 mt-4">سجلات من كيانات أخرى تخص هذا الكيان</h2>
        <p class="small text-muted">تظهر في صفحة هذا الكيان، ويعدّلها مالكها فقط.</p>
        <ul class="small">@foreach($linked as $record)<li>{{ $record->content_title }} — يملكه: {{ $record->ownerEntity?->name_ar }} ({{ $record->status_label }})</li>@endforeach</ul>
    @endif
</section>
<div class="mt-3">{{ $records->links() }}</div>
@endsection
