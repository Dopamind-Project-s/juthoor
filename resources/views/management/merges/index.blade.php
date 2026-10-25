@extends('layouts.management')
@section('page-heading','دمج السجلات المكررة')
@section('title','دمج السجلات المكررة')
@section('content')
<section class="management-panel">
    <span class="eyebrow">تحديد المكررات</span><h1 class="h4">سجلات قد تكون لشخص واحد</h1>
    <p class="text-muted">تُقترح الأزواج عند تطابق الاسم بعد التطبيع أو تهجئة بديلة مسجلة مع الأب نفسه (أو غياب الأب عن كليهما). التشابه وحده لا يكفي؛ لا يُدمج أي سجل تلقائيًا، والدمج من صلاحية مدير العائلة بعد المعاينة.</p>
    <div class="management-list">
    @forelse($candidates as $pair)
        <article><div><strong>{{ $pair['a']->display_name }} (#{{ $pair['a']->id }}) — {{ $pair['b']->display_name }} (#{{ $pair['b']->id }})</strong><small>{{ $pair['reason'] }} · {{ implode(' · ', $pair['signals']) }}</small></div>
        @if($canMerge)<div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-primary" href="{{ route('management.merges.preview',[$family->slug,'tree'=>$tree->slug,'survivor'=>$pair['a']->id,'merged'=>$pair['b']->id]) }}">معاينة: إبقاء #{{ $pair['a']->id }}</a>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('management.merges.preview',[$family->slug,'tree'=>$tree->slug,'survivor'=>$pair['b']->id,'merged'=>$pair['a']->id]) }}">معاينة: إبقاء #{{ $pair['b']->id }}</a>
        </div>@endif</article>
    @empty
        <p class="text-muted mb-0" role="status">لا توجد أزواج مشتبه بتكرارها في هذه الشجرة.</p>
    @endforelse
    </div>
</section>
@if($canMerge)
<section class="management-panel">
    <h2 class="h5">اختيار شخصين يدويًا</h2>
    <form method="get" action="{{ route('management.merges.preview',$family->slug) }}" class="management-grid"><input type="hidden" name="tree" value="{{ $tree->slug }}">
        <label>السجل الباقي<select name="survivor" class="form-select" required data-remote-select data-remote-url="{{ route('management.lookups.people',[$family->slug,'tree'=>$tree->slug]) }}"><option value="">اختر</option></select></label>
        <label>السجل المدموج فيه<select name="merged" class="form-select" required data-remote-select data-remote-url="{{ route('management.lookups.people',[$family->slug,'tree'=>$tree->slug]) }}"><option value="">اختر</option></select></label>
        <button class="btn btn-primary">معاينة الدمج</button>
    </form>
</section>
<section class="management-panel">
    <h2 class="h5">سجل عمليات الدمج</h2>
    <div class="management-list">
    @forelse($history as $operation)
        <article><div><strong>#{{ $operation->merged_id }} {{ $operation->merged?->full_name_ar }} ← دُمج في #{{ $operation->survivor_id }} {{ $operation->survivor?->full_name_ar }}</strong><small>{{ $operation->performer?->name }} · {{ $operation->created_at?->format('Y-m-d H:i') }} · {{ $operation->status === 'applied' ? 'مطبّق' : 'تم التراجع عنه' }}</small></div><a class="btn btn-sm btn-outline-primary" href="{{ route('management.merges.show',[$family->slug,$operation->id,'tree'=>$tree->slug]) }}">التفاصيل والتراجع</a></article>
    @empty
        <p class="text-muted mb-0">لم تُنفذ عمليات دمج بعد.</p>
    @endforelse
    </div>
</section>
@endif
@endsection
