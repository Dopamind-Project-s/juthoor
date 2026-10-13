@extends('layouts.management')
@section('page-heading','تفاصيل المقترح والمراجعة')
@section('content')
<section class="management-panel"><h1 class="h4">{{ $change->reason }}</h1><p>موضوع المقترح: {{ $change->target_type==='relationship' ? ($referenceLabels['person_id'][$change->payload['person_id']] ?? 'علاقة أبوة') : ($change->base['full_name_ar'] ?? $change->base['name_ar'] ?? $change->payload['full_name_ar'] ?? $change->payload['name_ar'] ?? 'سجل جديد') }}</p><p>الحالة: {{ ['pending'=>'بانتظار المراجعة','accepted'=>'مقبول','rejected'=>'مرفوض'][$change->status] }}</p>
@include('management.changes.diff',['before'=>$change->base ?? [],'after'=>$change->payload])
@if($change->applied_audit_id)<a class="btn btn-outline-primary" href="{{ route('management.audit.show',$change->applied_audit_id) }}">التغيير المعتمد وسجل التدقيق</a>@endif
@if($change->decision_reason)<p>سبب القرار: {{ $change->decision_reason }}</p>@endif
@if($change->status==='pending' && auth()->user()->can('review',$family) && $change->submitted_by!==auth()->id())<form method="post" class="workflow-confirmation" action="{{ route('management.changes.decide',[$family->slug,$change->id,'tree'=>$tree->slug]) }}">@csrf<label>سبب القرار<textarea name="decision_reason" class="form-control" required maxlength="2000"></textarea></label><button name="decision" value="accepted" class="btn btn-primary mt-3">قبول وتطبيق</button><button name="decision" value="rejected" class="btn btn-outline-danger mt-3">رفض المقترح</button></form>@endif
<a class="btn btn-outline-primary mt-3" href="{{ route('management.changes.index',[$family->slug,'tree'=>$tree->slug]) }}">قائمة المراجعة</a></section>
@endsection
