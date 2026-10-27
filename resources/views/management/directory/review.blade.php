@extends('layouts.management')
@section('page-heading','مراجعة دليل السند')
@section('title','مراجعة دليل السند')
@push('styles')<link href="{{ asset('css/sanad.css') }}" rel="stylesheet">@endpush
@section('content')
<section class="management-panel">
    <h1 class="h4">مراجعة دليل السند</h1>
    <p class="text-muted">يتحقق مدير العائلة من الانتماء ويعالج البلاغات ويمكنه إيقاف ظهور ملف مؤقتًا، لكنه لا يعدّل بيانات العضو ولا ينشر شيئًا نيابة عنه.</p>
<div class="sanad-review-counts"><span>{{ $pending->total() }} طلب انتماء</span><span>{{ $reports->total() }} بلاغ مفتوح</span><span>{{ $suspended->total() }} ملف موقوف</span></div>
    <p class="small text-muted mt-3">صلاحية مشرف الدليل تخص ملفات هذه العائلة فقط، ولا تمنحه تعديل الأنساب أو اعتماد المؤهلات.</p>
</section>
<section class="management-panel" data-pending-affiliations>
    <h2 class="h5">طلبات توثيق الانتماء</h2>
    <div class="management-list">
        @forelse($pending as $profile)
            <article><div><strong>{{ $profile->display_name }}</strong><small>الحساب: {{ $profile->user?->name }} · يطلب: {{ $profile->entity?->name_ar }}</small></div>
                <form method="post" action="{{ route('management.directory.affiliation', [$family->slug, $profile->id]) }}" class="d-flex flex-wrap gap-1">@csrf<input class="form-control form-control-sm" name="note" placeholder="ملاحظة" aria-label="ملاحظة"><button class="btn btn-sm btn-success" name="decision" value="verified">توثيق</button><button class="btn btn-sm btn-outline-danger" name="decision" value="rejected">رفض</button></form></article>
        @empty
            <p class="text-muted mb-0">لا توجد طلبات.</p>
        @endforelse
    </div>
{{ $pending->links() }}
</section>
<section class="management-panel" data-open-reports>
    <h2 class="h5">البلاغات المفتوحة</h2>
    <div class="management-list">
        @forelse($reports as $report)
            <article><div><strong>{{ $report->profile?->display_name }}</strong><small>{{ \App\Models\DirectoryReport::REASONS[$report->reason] }} · {{ $report->details }} · من {{ $report->reporter?->name }}</small></div>
                <form method="post" action="{{ route('management.directory.resolve', [$family->slug, $report->id]) }}" class="d-flex flex-wrap gap-1">@csrf<input class="form-control form-control-sm" name="note" placeholder="ملاحظة" aria-label="ملاحظة"><button class="btn btn-sm btn-outline-secondary" name="action" value="dismiss">إغلاق</button><button class="btn btn-sm btn-outline-danger" name="action" value="suspend">إيقاف الظهور</button></form></article>
        @empty
            <p class="text-muted mb-0">لا توجد بلاغات مفتوحة.</p>
        @endforelse
    </div>
{{ $reports->links() }}
</section>
<section class="management-panel">
    <h2 class="h5">ملفات موقوفة</h2>
    <div class="management-list">
        @forelse($suspended as $profile)
            <article><div><strong>{{ $profile->display_name }}</strong><small>{{ $profile->suspension_note }}</small></div><form method="post" action="{{ route('management.directory.reinstate', [$family->slug, $profile->id]) }}">@csrf<button class="btn btn-sm btn-outline-primary">إعادة الإظهار</button></form></article>
        @empty
            <p class="text-muted mb-0">لا توجد ملفات موقوفة.</p>
        @endforelse
    </div>
{{ $suspended->links() }}
</section>
<section class="management-panel"><h2 class="h5">سجل قرارات البلاغات</h2><div class="management-list">@forelse($history as $report)<article><div><strong>{{ $report->profile?->display_name ?? 'ملف منسحب' }}</strong><small>{{ \App\Models\DirectoryReport::REASONS[$report->reason] }} · {{ $report->status === 'actioned' ? 'أُوقف الظهور' : 'أُغلق البلاغ' }} · {{ $report->resolved_at?->format('Y-m-d') }}</small><p>{{ $report->resolution_note }}</p></div></article>@empty<p class="text-muted">لا توجد قرارات مسجلة بعد.</p>@endforelse</div>{{ $history->links() }}</section>
@endsection
