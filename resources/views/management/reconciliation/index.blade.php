@extends('layouts.management')
@section('page-heading','مطابقة الأسماء التاريخية')
@section('title','مطابقة الأسماء التاريخية')
@php
    $privacy = app(\App\Services\Genealogy\PersonPrivacy::class);
    $user = auth()->user();
    $stateClasses = ['proposed' => 'text-bg-secondary', 'authoritative' => 'text-bg-success', 'alternative' => 'text-bg-info', 'rejected' => 'text-bg-danger', 'superseded' => 'text-bg-light'];
    $verificationLabels = ['pending_review' => 'مسجلة دون تحقق', 'sourced' => 'مسندة إلى مصدر', 'verified' => 'موثقة', 'unresolved' => 'محل خلاف'];
    $actionLabels = ['created' => 'تسجيل', 'updated' => 'تصنيف', 'accepted' => 'قبول', 'rejected' => 'رفض', 'superseded' => 'استبدال', 'pending' => 'تقديم مقترح', 'deleted' => 'حذف'];
@endphp
@section('content')
<section class="management-panel">
    <span class="eyebrow">الجذر المشترك</span>
    <h1 class="h4">اختلافات أسماء أجداد الجذر بين المصادر</h1>
    <p class="text-muted">كل اختلاف مسجل روايةً مستقلة مع مصدره، ولا يغيّر الشجرة. الاسم المعروض لا يتغير إلا بمقترح يقبله مراجع، وقبول تهجئة بديلة يضيفها إلى التهجئات دون تغيير هوية الشخص. يمكن إبقاء الاختلاف «محل خلاف» دون حسم حتى تتوفر أدلة.</p>
    <dl class="reconciliation-summary">
        <div><dt>المسجلة</dt><dd>{{ $summary['total'] }}</dd></div>
        <div><dt>بانتظار المراجعة</dt><dd>{{ $summary['open'] }}</dd></div>
        <div><dt>محل خلاف</dt><dd>{{ $summary['unresolved'] }}</dd></div>
        <div><dt>تهجئات مقبولة</dt><dd>{{ $summary['alternative'] }}</dd></div>
        <div><dt>معتمدة للعرض</dt><dd>{{ $summary['authoritative'] }}</dd></div>
        <div><dt>مرفوضة</dt><dd>{{ $summary['rejected'] }}</dd></div>
    </dl>
    <nav class="d-flex flex-wrap gap-2" aria-label="تصفية الاختلافات">
        @foreach(\App\Services\Genealogy\NameReconciliationService::FILTERS as $key => $label)
            <a class="btn btn-sm {{ $filter === $key ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('management.reconciliation.index',[$family->slug,'tree'=>$tree->slug,'status'=>$key]) }}" @if($filter === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
</section>

@forelse($rows as $row)
    @php
        $claim = $row['claim'];
        $person = $claim->person;
        $visible = $privacy->canViewFact($claim, $user);
        $isOpen = $claim->state === 'proposed';
        $isShared = $row['sharedFamilies']->isNotEmpty();
    @endphp
    <article class="management-panel reconciliation-claim" id="claim-{{ $claim->id }}" data-claim="{{ $claim->import_key }}">
        <header class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <h2 class="h6 mb-0"><span class="badge text-bg-dark">{{ $person?->canonical_key ?? '—' }}</span>
                @if($person)<a href="{{ route('management.people.show',[$family->slug,$person->id,'tree'=>$tree->slug]) }}#facts">{{ $person->full_name_ar }}</a>@if($person->merged_into_id)<span class="badge text-bg-warning">مدموج</span>@endif @else سجل محذوف @endif
            </h2>
            <div class="d-flex flex-wrap gap-1">
                <span class="badge {{ $stateClasses[$claim->state] }}">{{ \App\Models\Assertion::STATES[$claim->state] }}</span>
                <span class="badge text-bg-light">{{ \App\Models\Assertion::RELATIONS[$claim->relation] ?? $claim->relation }}</span>
                <span class="badge text-bg-light">{{ $verificationLabels[$claim->verification_status] ?? $claim->verification_status }}</span>
            </div>
        </header>
        <dl class="reconciliation-facts">
            <div><dt>الاسم المعروض</dt><dd>{{ $person?->full_name_ar ?? '—' }}@if($person?->alternate_names)<small class="text-muted d-block">تهجئات مقبولة: {{ implode('، ', $person->alternate_names) }}</small>@endif</dd></div>
            <div><dt>التهجئة المقترحة</dt><dd><strong>@if($visible){{ $claim->value }}@else<i class="bi bi-lock"></i> محجوبة@endif</strong></dd></div>
            <div><dt>المصدر الأصلي</dt><dd>{{ $row['dataset'] }}</dd></div>
            <div><dt>الفروع المتأثرة</dt><dd>@if($row['branches']->isEmpty())<span class="text-muted">لا فروع مؤسسة تحته</span>@else{{ $row['branches']->take(6)->pluck('name_ar')->implode('، ') }}@if($row['branches']->count() > 6)<small class="text-muted"> و{{ $row['branches']->count() - 6 }} أخرى</small>@endif @endif</dd></div>
        </dl>
        @if($isShared)<div class="alert alert-warning py-2 mb-2" role="status"><strong>جد مشترك</strong> مع {{ $row['sharedFamilies']->pluck('name_ar')->implode('، ') }}؛ البت في اسمه من صلاحية مشرف المنصة.</div>@endif
        @if($claim->reviewed_at)<p class="small mb-2">قرار المراجعة: {{ $claim->review_reason }} — {{ $claim->reviewer?->name ?? 'النظام' }} · {{ $claim->reviewed_at->format('Y-m-d') }}</p>@endif

        <div class="row g-3">
            <div class="col-lg-6">
                <h3 class="h6">الأدلة</h3>
                <ul class="small mb-2">
                    @forelse($claim->citations as $citation)
                        <li>@if($visible)<a href="{{ route('management.sources.show',[$family->slug,$citation->source_id,'tree'=>$tree->slug]) }}">{{ $citation->source->title }}</a>{{ $citation->locator ? ' · '.$citation->locator : '' }} · {{ $verificationLabels[$citation->verification_status] ?? $citation->verification_status }}@else<i class="bi bi-lock"></i> دليل محجوب@endif</li>
                    @empty
                        <li class="text-muted">لا دليل مرفق.</li>
                    @endforelse
                </ul>
                <h3 class="h6">الروايات الأخرى لاسم هذا الشخص</h3>
                <ul class="small mb-0">
                    @forelse($row['competing'] as $other)
                        <li>@if($privacy->canViewFact($other, $user)){{ $other->value }}@else<i class="bi bi-lock"></i> محجوبة@endif · {{ \App\Models\Assertion::FACTS[$other->fact_type]['label'] }} · {{ \App\Models\Assertion::STATES[$other->state] }} · {{ $verificationLabels[$other->verification_status] ?? $other->verification_status }} · {{ $other->citations->count() }} دليل</li>
                    @empty
                        <li class="text-muted">لا روايات منافسة مسجلة.</li>
                    @endforelse
                </ul>
            </div>
            <div class="col-lg-6">
                @if($row['pendingChange'])
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('management.changes.show',[$family->slug,$row['pendingChange']->id,'tree'=>$tree->slug]) }}">مقترح الاعتماد قيد المراجعة</a>
                @elseif($isOpen && $visible)
                    <details class="mb-2"><summary class="small">تصنيف الاختلاف أو إبقاؤه دون حسم</summary>
                        <form method="post" class="management-grid mt-2" action="{{ route('management.assertions.update',[$family->slug,$claim->id,'tree'=>$tree->slug]) }}">@csrf @method('PUT')
                            <label>نوع الاختلاف<select name="relation" class="form-select">@foreach(\App\Models\Assertion::RELATIONS as $value=>$label)<option value="{{ $value }}" @selected($claim->relation===$value)>{{ $label }}</option>@endforeach</select></label>
                            <label>حالة التحقق<select name="verification_status" class="form-select">@foreach($verificationLabels as $value=>$label)<option value="{{ $value }}" @selected($claim->verification_status===$value) @disabled($value==='verified' && ! $canPublish && $claim->verification_status!=='verified')>{{ $label }}</option>@endforeach</select></label>
                            <button class="btn btn-sm btn-outline-primary">حفظ التصنيف</button>
                        </form>
                    </details>
                    @if($sources->isNotEmpty())
                    <details class="mb-2"><summary class="small">إرفاق دليل</summary>
                        <form method="post" class="management-grid mt-2" action="{{ route('management.assertions.cite',[$family->slug,$claim->id,'tree'=>$tree->slug]) }}">@csrf
                            <label>المصدر<select class="form-select" name="source_id" required>@foreach($sources as $source)<option value="{{ $source->id }}">{{ $source->title }}</option>@endforeach</select></label>
                            <label>الموضع (صفحة/لوحة)<input class="form-control" name="locator" maxlength="255"></label>
                            <label>درجة دعم الدليل<select name="verification_status" class="form-select"><option value="pending_review">لم يُفحص</option><option value="sourced">يدعم الرواية</option><option value="unresolved">يخالفها أو غير واضح</option>@if($canPublish)<option value="verified">موثق</option>@endif</select></label>
                            <button class="btn btn-sm btn-outline-primary">إرفاق الدليل</button>
                        </form>
                    </details>
                    @endif
                    <details class="mb-2"><summary class="small">{{ $claim->relation === 'alternate_spelling' ? 'اقتراح قبولها تهجئةً بديلة' : 'اقتراح تصحيح الاسم المعروض' }}</summary>
                        <p class="small text-muted mt-2 mb-1">@if($claim->relation === 'alternate_spelling')تُضاف إلى التهجئات المقبولة ويبقى الاسم المعروض وهوية الشخص كما هما.@else يصبح الاسم المعروض بعد قبول المراجع، وتُحفظ القيمة السابقة رواية مستبدلة. لقبولها تهجئةً فقط صنّفها «تهجئة بديلة» أولًا.@endif</p>
                        <form method="post" class="management-grid" action="{{ route('management.assertions.propose',[$family->slug,$claim->id,'tree'=>$tree->slug]) }}">@csrf
                            <label>سبب الاقتراح والأدلة<textarea name="reason" class="form-control" required maxlength="2000"></textarea></label>
                            <button class="btn btn-sm btn-primary">إرسال للمراجعة</button>
                        </form>
                    </details>
                    @if($canReview)
                    <details class="mb-2"><summary class="small">رفض الرواية مع السبب</summary>
                        <form method="post" class="management-grid mt-2" action="{{ route('management.assertions.decline',[$family->slug,$claim->id,'tree'=>$tree->slug]) }}">@csrf
                            <label>سبب الرفض<textarea name="reason" class="form-control" required maxlength="2000"></textarea></label>
                            <button class="btn btn-sm btn-outline-danger">رفض الرواية (تبقى محفوظة)</button>
                        </form>
                    </details>
                    @endif
                @endif
                <details><summary class="small">سجل القرارات ({{ $row['history']->count() }})</summary>
                    <ol class="small mt-2 mb-0">
                        @forelse($row['history'] as $log)
                            <li>{{ $log->created_at?->format('Y-m-d H:i') }} · {{ $log->user?->name ?? 'استيراد/النظام' }} · {{ $actionLabels[$log->action] ?? $log->action }} ({{ class_basename($log->auditable_type) }})@if(($log->after['review_reason'] ?? null) && $log->auditable_type === $claim->getMorphClass()): {{ $log->after['review_reason'] }}@endif</li>
                        @empty
                            <li class="text-muted">لا قرارات مسجلة.</li>
                        @endforelse
                    </ol>
                </details>
            </div>
        </div>
    </article>
@empty
    <section class="management-panel"><p class="text-muted mb-0" role="status">لا اختلافات في هذا التصنيف.@if($summary['total'] === 0) لم تُستورد اختلافات الجذر لهذه الشجرة.@endif</p></section>
@endforelse
@endsection
