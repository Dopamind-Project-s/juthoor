@extends('layouts.management')
@section('page-heading','مراجعة محتوى الكيانات')
@section('title','مراجعة محتوى الكيانات')
@section('content')
<section class="management-panel">
    <span class="eyebrow">الصفحات المجتمعية</span>
    <h1 class="h4">محتوى بانتظار المراجعة</h1>
    <p class="text-muted">سجلات أرسلها المحررون للمراجعة في كيانات تملك صلاحية نشرها. النشر أو الإعادة إلى المسودة مع ذكر السبب يتمان من صفحة السجل.</p>
    <div class="management-list">
        @forelse($pending as $row)
            @php($record = $row['record'])
            <article data-queue-record="{{ $row['module'] }}-{{ $record->id }}">
                <div><strong>{{ $record->content_title }}</strong><small>{{ \App\Services\Community\CommunityContentModules::MODULES[$row['module']]['label'] }} · {{ $record->ownerEntity?->name_ar }} · {{ $record->creator?->name ?? '—' }} · {{ $record->submitted_at?->format('Y-m-d H:i') }}</small></div>
                <a class="btn btn-sm btn-primary" href="{{ route('management.entities.content.edit', [$family->slug, $record->owner_entity_id, $row['module'], $record->id]) }}">مراجعة</a>
            </article>
        @empty
            <p class="text-muted py-3" role="status">لا يوجد محتوى بانتظار مراجعتك.</p>
        @endforelse
    </div>
</section>
@endsection
