@extends('layouts.management')
@section('page-heading','تفاصيل المقترح والمراجعة')
@section('content')
<section class="management-panel"><h1 class="h4">{{ $change->reason }}</h1><p>موضوع المقترح: {{ $change->target_type==='relationship' ? ($referenceLabels['person_id'][$change->payload['person_id']] ?? 'علاقة أبوة') : ($change->base['full_name_ar'] ?? $change->base['name_ar'] ?? $change->payload['full_name_ar'] ?? $change->payload['name_ar'] ?? 'سجل جديد') }}</p><p>الحالة: {{ ['pending'=>'بانتظار المراجعة','accepted'=>'مقبول','rejected'=>'مرفوض'][$change->status] }}</p>
@include('management.changes.diff',['before'=>$change->base ?? [],'after'=>$change->payload])
@if($sharedFamilies->isNotEmpty())<div class="alert alert-warning" role="status"><strong>يمس جدًا مشتركًا:</strong> العائلات المتأثرة: {{ $sharedFamilies->pluck('name_ar')->implode('، ') }}. القبول من صلاحية مشرف المنصة.</div>@endif
@if($claim)
@php($privacy = app(\App\Services\Genealogy\PersonPrivacy::class))
<section class="fact-group" aria-label="الرواية المقترح اعتمادها"><h2 class="h6">الرواية المقترح اعتمادها: {{ $claim->label }}</h2>
@foreach([$claim, ...$competing] as $item)
@php($visible = $privacy->canViewFact($item, auth()->user()))
<div class="fact-claim">@if($item->is($claim))<span class="badge text-bg-primary">هذا المقترح</span> @endif<strong>@if($visible){{ $item->claim_text }}@else<i class="bi bi-lock"></i> محجوب@endif</strong> <span class="badge text-bg-light">{{ \App\Models\Assertion::STATES[$item->state] }}</span> <span class="badge text-bg-light">{{ \App\Models\Assertion::RELATIONS[$item->relation] ?? $item->relation }}</span>
<ul class="small mb-0">@forelse($item->citations as $citation)<li>@if($visible){{ $citation->source->title }}{{ $citation->locator ? ' · '.$citation->locator : '' }}@else<i class="bi bi-lock"></i> دليل محجوب@endif</li>@empty<li class="text-muted">لا دليل مرفق.</li>@endforelse</ul></div>
@endforeach
<p class="small text-muted">القبول يغيّر القيمة المعروضة ويحفظ القيمة السابقة رواية مستبدلة؛ الرفض يحفظ الرواية مرفوضة مع السبب. لا يُدمج أي شخص.</p></section>
@endif
@if($change->applied_audit_id)<a class="btn btn-outline-primary" href="{{ route('management.audit.show',$change->applied_audit_id) }}">التغيير المعتمد وسجل التدقيق</a>@endif
@if($change->decision_reason)<p>سبب القرار: {{ $change->decision_reason }}</p>@endif
@if($possibleDuplicates->isNotEmpty())<div class="alert alert-warning" role="status"><strong>سجلات قائمة بالاسم نفسه ودون أب مسجل:</strong> {{ app(\App\Services\Genealogy\DuplicatePersonDetector::class)->describe($possibleDuplicates) }}. قارن السجلات قبل القبول؛ لا يُدمج أي سجل تلقائيًا.</div>@endif
@if($change->status==='pending' && auth()->user()->can('review',$family) && $change->submitted_by!==auth()->id())<form method="post" class="workflow-confirmation" action="{{ route('management.changes.decide',[$family->slug,$change->id,'tree'=>$tree->slug]) }}">@csrf<label>سبب القرار<textarea name="decision_reason" class="form-control" required maxlength="2000"></textarea></label>@if($possibleDuplicates->isNotEmpty())<label class="duplicate-confirmation mt-2"><span><input type="checkbox" name="confirm_distinct" value="1"> راجعت السجلات المطابقة وأؤكد أنه شخص مختلف</span></label>@endif<button name="decision" value="accepted" class="btn btn-primary mt-3">قبول وتطبيق</button><button name="decision" value="rejected" class="btn btn-outline-danger mt-3">رفض المقترح</button></form>@endif
<a class="btn btn-outline-primary mt-3" href="{{ route('management.changes.index',[$family->slug,'tree'=>$tree->slug]) }}">قائمة المراجعة</a></section>
@endsection
