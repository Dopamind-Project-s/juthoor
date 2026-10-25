@extends('layouts.management')
@section('page-heading','سجل الاسترجاع والاستعادة')
@section('content')
<section class="management-panel">
    <h1 class="h4">عمليات الاسترجاع والاستعادة</h1>
    <p class="text-muted">كل استرجاع عملية تدقيق جديدة مرتبطة بالعملية الأصلية؛ عمليات الدمج والتراجع عنها في صفحة الدمج.</p>
    <div class="management-list">
    @forelse($recoveries as $recovery)
        @php($original = $originals[$recovery->reverted_from_id] ?? null)
        <article><div><strong>{{ $recovery->action === 'restored' ? 'استعادة' : 'استرجاع' }} · {{ class_basename($recovery->auditable_type) }} #{{ $recovery->auditable_id }}</strong>
            <small>{{ $recovery->user?->name ?? 'مستخدم محذوف' }} · {{ $recovery->created_at?->format('Y-m-d H:i') }} · السبب: {{ $recovery->reason }} · العملية الأصلية: {{ $original?->action }} بواسطة {{ $original?->user?->name ?? 'النظام' }}</small></div>
            <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="{{ route('management.audit.show',$recovery->id) }}">عملية الاسترجاع</a>@if($original)<a class="btn btn-sm btn-outline-primary" href="{{ route('management.audit.show',$original->id) }}">العملية الأصلية</a>@endif
            @if($recovery->auditable_type === \App\Models\Person::class && \App\Models\Person::query()->whereKey($recovery->auditable_id)->exists())<a class="btn btn-sm btn-outline-primary" href="{{ route('management.people.show',[$family->slug,$recovery->auditable_id]) }}">السجل</a>@endif</div></article>
    @empty
        <p class="text-muted mb-0">لا توجد عمليات استرجاع بعد.</p>
    @endforelse
    </div>
    {{ $recoveries->links() }}
</section>
@endsection
