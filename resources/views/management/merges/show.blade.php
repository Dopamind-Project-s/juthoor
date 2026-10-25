@extends('layouts.management')
@section('page-heading','عملية دمج')
@section('title','عملية دمج')
@section('content')
@php
    $tables = ['people' => 'سجلات الأشخاص', 'person_relationships' => 'علاقات الأبوة', 'branches' => 'الفروع', 'citations' => 'الاستشهادات', 'person_sources' => 'روابط المصادر',
        'person_attribute_assignments' => 'الصفات', 'assertions' => 'الروايات', 'cross_family_links' => 'الروابط بين العائلات', 'family_trees' => 'جذر الشجرة'];
    $ops = ['update' => 'نقل/تحديث', 'delete' => 'توحيد (يُعاد عند التراجع)', 'insert' => 'إنشاء رواية محفوظة'];
    $summary = collect($operation->journal)->groupBy(fn ($entry) => $entry['table'].'|'.$entry['op'])->map->count();
@endphp
<section class="management-panel">
    <span class="status-badge">{{ $operation->status === 'applied' ? 'مطبّق' : 'تم التراجع عنه' }}</span>
    <h1 class="h4 mt-2">دمج #{{ $operation->merged_id }} في #{{ $operation->survivor_id }}</h1>
    <dl class="dashboard-facts">
        <dt>السجل الباقي</dt><dd>@if($operation->survivor && ! $operation->survivor->merged_into_id)<a href="{{ route('management.people.show',[$family->slug,$operation->survivor_id,'tree'=>$tree->slug]) }}">{{ $operation->survivor->display_name }}</a>@else{{ $operation->survivor?->display_name }}@endif</dd>
        <dt>السجل المدموج</dt><dd>{{ $operation->merged?->display_name }} (#{{ $operation->merged_id }})</dd>
        <dt>المنفذ</dt><dd>{{ $operation->performer?->name ?? 'مستخدم محذوف' }} · {{ $operation->created_at?->format('Y-m-d H:i') }}</dd>
        <dt>السبب</dt><dd>{{ $operation->reason }}</dd>
        @if($operation->undone_at)<dt>التراجع</dt><dd>{{ $operation->undoer?->name }} · {{ $operation->undone_at->format('Y-m-d H:i') }} · {{ $operation->undo_reason }}</dd>@endif
    </dl>
    <h2 class="h6">ما غيّره الدمج</h2>
    <ul>@foreach($summary as $key => $count)@php([$table, $op] = explode('|', $key))<li>{{ $tables[$table] ?? $table }}: {{ $ops[$op] }} ({{ $count }})</li>@endforeach</ul>
</section>
@if($operation->status === 'applied')
<section class="management-panel">
    <h2 class="h5">التراجع عن الدمج</h2>
    @if($blockers)
        <div class="alert alert-warning" role="status"><strong>التراجع الآلي غير آمن الآن؛ تغيرت سجلات لمسها الدمج:</strong><ul class="mb-0">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
        <p class="text-muted mb-0">للاسترداد المضبوط: راجع التغييرات اللاحقة المذكورة في سجل التدقيق، وأعدها إلى حالتها بعد الدمج أو سجّل القرار البديل عبر الروايات والمقترحات، ثم أعد فتح هذه الصفحة. لا يُكتب فوق أي عمل أحدث.</p>
    @else
        <p class="text-muted">يُعاد السجل المدموج بهويته وعلاقاته وأدلته ورواياته كما كانت، وتبقى التغييرات الأحدث غير المرتبطة بالدمج كما هي.</p>
        <form method="post" action="{{ route('management.merges.undo',[$family->slug,$operation->id,'tree'=>$tree->slug]) }}" class="management-grid">@csrf
            <label>سبب التراجع<textarea name="reason" class="form-control" required maxlength="2000"></textarea></label>
            <button class="btn btn-outline-danger">التراجع عن الدمج</button>
        </form>
    @endif
</section>
@endif
<a class="btn btn-outline-primary" href="{{ route('management.merges.index',[$family->slug,'tree'=>$tree->slug]) }}">كل عمليات الدمج</a>
@endsection
