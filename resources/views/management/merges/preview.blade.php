@extends('layouts.management')
@section('page-heading','معاينة الدمج')
@section('title','معاينة الدمج')
@section('content')
@php
    $privacy = app(\App\Services\Genealogy\PersonPrivacy::class);
    $user = auth()->user();
    [$survivor, $merged] = [$plan['survivor'], $plan['merged']];
    $sensitive = \App\Services\Genealogy\PersonPrivacy::FIELDS;
    $display = function ($field, $value, $person) use ($privacy, $user, $sensitive) {
        if ($value === null || $value === '') { return '—'; }
        if (isset($sensitive[$field]) && ! $privacy->canView($person, $field, $user)) { return '🔒 محجوب'; }
        return match ($field) {
            'is_living' => in_array($value, [true, 1, '1'], true) ? 'على قيد الحياة' : 'متوفى',
            'gender' => ['male' => 'ذكر', 'female' => 'أنثى'][$value] ?? $value,
            default => $value,
        };
    };
    $outcomes = ['same' => 'متطابقة', 'keep' => 'تبقى قيمة السجل الباقي', 'fill' => 'تُكمَّل من السجل المدموج', 'conflict' => 'متعارضة — يلزم اختيار'];
    $query = ['tree' => $tree->slug, 'survivor' => $survivor->id, 'merged' => $merged->id];
    $canConfirm = $plan['blockers'] === [] && (! $plan['requiresSuperAdmin'] || $user->isSuperAdmin());
@endphp
<section class="management-panel">
    <span class="eyebrow">معاينة فقط — لا يتغير شيء قبل التأكيد</span>
    <h1 class="h4">دمج #{{ $merged->id }} في #{{ $survivor->id }}</h1>
    <p class="text-muted">يبقى السجل #{{ $survivor->id }} بمعرّفه ومفتاحه ({{ $survivor->canonical_key ?: 'دون مفتاح' }}). يُحفظ السجل #{{ $merged->id }} بتاريخه ويُوجَّه إلى الباقي، ولا يُحذف. يمكن التراجع لاحقًا ما لم تتغير السجلات المتأثرة.</p>
    <a class="btn btn-sm btn-outline-primary" href="{{ route('management.merges.preview',[$family->slug,'tree'=>$tree->slug,'survivor'=>$merged->id,'merged'=>$survivor->id]) }}">عكس الاختيار: إبقاء #{{ $merged->id }}</a>

    @if($plan['blockers'])
        <div class="alert alert-danger mt-3" role="alert"><strong>لا يمكن الدمج حاليًا:</strong><ul class="mb-0">@foreach($plan['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
    @endif
    @if($plan['impactedFamilies']->isNotEmpty())
        <div class="alert alert-warning mt-3" role="status"><strong>يمس عائلات أخرى:</strong> {{ $plan['impactedFamilies']->pluck('name_ar')->implode('، ') }}. ينفذ الدمج مشرف المنصة فقط.</div>
    @endif
</section>

<section class="management-panel">
    <h2 class="h5">مقارنة السجلين</h2>
    <form method="get" action="{{ route('management.merges.preview',$family->slug) }}">
        @foreach($query as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
        <div class="table-responsive"><table class="table merge-table">
            <thead><tr><th scope="col">المعلومة</th><th scope="col">الباقي #{{ $survivor->id }}</th><th scope="col">المدموج #{{ $merged->id }}</th><th scope="col">النتيجة</th></tr></thead>
            <tbody>
            <tr><th scope="row">الأب</th><td>{{ $plan['survivorFather']?->display_name ?? '—' }}</td><td>{{ $plan['mergedFather']?->display_name ?? '—' }}</td><td>{{ $plan['survivorFather'] && $plan['mergedFather'] && ! $plan['survivorFather']->is($plan['mergedFather']) ? 'متعارض — يمنع الدمج' : 'سليم' }}</td></tr>
            @foreach($plan['fields'] as $field => $comparison)
                @continue($comparison['outcome'] === 'same' && $comparison['survivor'] === null)
                <tr class="{{ $comparison['outcome'] === 'conflict' ? 'table-warning' : '' }}"><th scope="row">{{ $comparison['label'] }}</th>
                    <td>{{ $display($field, $comparison['survivor'], $survivor) }}</td><td>{{ $display($field, $comparison['merged'], $merged) }}</td>
                    <td>@if($comparison['outcome'] === 'conflict')
                        <label class="d-block"><input type="radio" name="decisions[{{ $field }}]" value="survivor" @checked($comparison['choice']==='survivor')> قيمة الباقي</label>
                        <label class="d-block"><input type="radio" name="decisions[{{ $field }}]" value="merged" @checked($comparison['choice']==='merged')> قيمة المدموج</label>
                    @else{{ $outcomes[$comparison['outcome']] }}@endif</td></tr>
            @endforeach
            </tbody>
        </table></div>
        <button class="btn btn-outline-primary">تحديث المعاينة بالاختيارات</button>
    </form>
    <dl class="dashboard-facts mt-3">
        <dt>تهجئات بديلة بعد الدمج</dt><dd>{{ $plan['alternateNames'] ? implode('، ', $plan['alternateNames']) : '—' }}</dd>
        <dt>الصفات بعد الدمج</dt><dd>{{ $plan['attributes'] ? implode('، ', $plan['attributes']) : '—' }}</dd>
        <dt>خصوصية الحقول</dt><dd>@forelse($plan['fieldVisibility'] as $field => $level){{ \App\Services\Genealogy\PersonPrivacy::LABELS[$field] }}: {{ \App\Services\Genealogy\PersonPrivacy::LEVELS[$level] }}@if(! $loop->last)، @endif @empty الافتراضية @endforelse <small class="text-muted d-block">يُعتمد الإعداد الأشد تقييدًا لكل حقل.</small></dd>
        <dt>النشر للعامة</dt><dd>الباقي: {{ $plan['publication']['survivor'] ? 'منشور' : 'غير منشور' }} · المدموج: {{ $plan['publication']['merged'] ? 'منشور' : 'غير منشور' }} · بعد الدمج: {{ $plan['publication']['result'] ? 'منشور' : 'غير منشور' }}</dd>
        <dt>روايات تُحفظ مستبدلة</dt><dd>{{ $plan['claims'] ? collect($plan['claims'])->map(fn ($field) => $plan['fields'][$field]['label'])->implode('، ') : 'لا شيء' }}</dd>
    </dl>
</section>

<section class="management-panel">
    <h2 class="h5">السجلات التي يتغير مرجعها</h2>
    <div class="management-list">
        @forelse($plan['references'] as $reference)
            <article><div><strong>{{ $reference['label'] }} ({{ $reference['count'] }})</strong><small>{{ $reference['action'] }}</small></div></article>
        @empty
            <p class="text-muted mb-0">لا توجد سجلات مرتبطة بالسجل المدموج.</p>
        @endforelse
    </div>
</section>

<section class="management-panel">
    <h2 class="h5">تأكيد الدمج</h2>
    @if($canConfirm)
        <form method="post" action="{{ route('management.merges.store',[$family->slug,'tree'=>$tree->slug]) }}" class="management-grid">@csrf
            <input type="hidden" name="survivor" value="{{ $survivor->id }}"><input type="hidden" name="merged" value="{{ $merged->id }}"><input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}">
            @foreach($plan['fields'] as $field => $comparison)@if($comparison['choice'])<input type="hidden" name="decisions[{{ $field }}]" value="{{ $comparison['choice'] }}">@endif @endforeach
            <label>سبب الدمج والأدلة<textarea name="reason" class="form-control" required maxlength="2000"></textarea></label>
            <label><input type="checkbox" name="confirm" value="1"> راجعت المعاينة وأؤكد أن السجلين لشخص واحد</label>
            <button class="btn btn-danger">دمج السجلين</button>
        </form>
    @else
        <p class="text-muted mb-0">يتاح التأكيد بعد حل الموانع أعلاه{{ $plan['requiresSuperAdmin'] && ! $user->isSuperAdmin() ? ' ومن حساب مشرف المنصة' : '' }}.</p>
    @endif
</section>
@endsection
