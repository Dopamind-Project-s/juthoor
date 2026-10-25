@extends('layouts.management')
@section('page-heading', $log->action === 'deleted' ? 'معاينة استعادة سجل محذوف' : 'معاينة استرجاع تغيير')
@section('content')
@php
    $privacy = app(\App\Services\Genealogy\PersonPrivacy::class);
    $isPerson = $log->auditable_type === \App\Models\Person::class;
    $mask = fn (array $snapshot) => $isPerson && $log->family ? $privacy->redactSnapshot($snapshot, $log->family, auth()->user()) : $snapshot;
    $restore = $context && $context['operation'] === 'restore';
    $effects = ['delete' => 'حذف السجل الذي أنشأته هذه العملية، إن لم ترتبط به اعتماديات جديدة.', 'update' => 'إعادة السجل إلى قيمه قبل العملية عبر مسار التعديل المعتمد.', 'restore' => 'إعادة إنشاء السجل المحذوف بمعرّفه الأصلي من لقطة الحذف المحفوظة.'];
@endphp
<section class="management-panel">
    <span class="eyebrow">معاينة فقط — لا يتغير شيء قبل التأكيد</span>
    <h1 class="h4">{{ $log->action === 'deleted' ? 'استعادة' : 'استرجاع' }} العملية #{{ $log->id }}</h1>
    <dl class="dashboard-facts">
        <dt>العملية الأصلية</dt><dd>{{ $log->action }} · {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</dd>
        <dt>المنفذ والتاريخ</dt><dd>{{ $log->user?->name ?? 'النظام' }} · {{ $log->created_at?->format('Y-m-d H:i') }}</dd>
    </dl>
    <p class="text-muted">لا يُحذف سجل التدقيق الأصلي. يُسجَّل الاسترجاع عملية جديدة مرتبطة به، ولا يمكن استرجاع العملية نفسها مرتين.</p>
    @if($blocked)
        <div class="alert alert-warning" role="status"><strong>الاسترجاع الآلي غير متاح:</strong><ul class="mb-0">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
        <p class="text-muted">للاسترداد اليدوي: عالج الموانع المذكورة (مثل استعادة الأشخاص المرتبطين أولًا أو التراجع عن التعديلات الأحدث) ثم أعد فتح المعاينة. لا يُكتب فوق أي عمل أحدث.</p>
    @else
        <p>الأثر: {{ $effects[$context['operation']] }}</p>
        @if($context['sharedFamilies']->isNotEmpty())<div class="alert alert-warning" role="status">يمس جدًا مشتركًا مع: {{ $context['sharedFamilies']->pluck('name_ar')->implode('، ') }}. التأكيد من صلاحية مشرف المنصة.</div>@endif
        @if($context['unpublished'])<div class="alert alert-info" role="status">كان السجل منشورًا عند حذفه؛ يُستعاد «قيد المراجعة» ولا يُنشر إلا بقرار مستقل.</div>@endif
        @if($context['publishes'])<div class="alert alert-warning" role="status">الاسترجاع سيجعل السجل منشورًا للعامة.</div>@endif
        @if($context['broadens'])<div class="alert alert-warning" role="status">الاسترجاع يوسّع الخصوصية الحالية للحقول: {{ implode('، ', $context['broadens']) }}.</div>@endif
        @include('management.changes.diff', ['before' => $mask($context['current']), 'after' => $mask($context['proposed'])])
        @if($context['relatedDeletions']->isNotEmpty())
            <h2 class="h6 mt-3">سجلات محذوفة مرتبطة بهذا الشخص</h2>
            <ul>@foreach($context['relatedDeletions'] as $related)<li>{{ class_basename($related->auditable_type) }} #{{ $related->auditable_id }} · <a href="{{ route('management.audit.revert.preview', $related->id) }}">معاينة استعادته بعد استعادة الشخص</a></li>@endforeach</ul>
        @endif
        <form method="post" class="workflow-confirmation" action="{{ route('management.audit.revert.store',$log->id) }}">@csrf
            <input type="hidden" name="fingerprint" value="{{ $context['fingerprint'] }}">
            <label>سبب {{ $restore ? 'الاستعادة' : 'الاسترجاع' }}<textarea name="reason" class="form-control" required maxlength="2000"></textarea></label>
            @if($context['publishes'] || $context['broadens'])<label class="d-block mt-2"><input type="checkbox" name="confirm_scope" value="1"> راجعت توسيع النشر أو الخصوصية وأوافق عليه</label>@endif
            <label class="d-block mt-2"><input type="checkbox" name="confirm" value="1" required> راجعت الأثر وأؤكد</label>
            <button class="btn btn-outline-danger mt-3">{{ $restore ? 'تأكيد الاستعادة' : 'تأكيد الاسترجاع' }}</button>
        </form>
    @endif
    <a class="btn btn-outline-primary mt-3" href="{{ route('management.audit.show',$log->id) }}">تفاصيل العملية الأصلية</a>
</section>
@endsection
