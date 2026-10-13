@extends('layouts.management')
@section('page-heading','تقديم مقترح تغيير')
@section('content')
<section class="management-panel"><div class="management-tabs">@foreach(['person'=>'شخص','relationship'=>'علاقة أبوة','branch'=>'فرع'] as $value=>$label)<a href="{{ route('management.changes.create',[$family->slug,'tree'=>$tree->slug,'kind'=>$value]) }}">{{ $label }}</a>@endforeach</div>
<form method="get" action="{{ route('management.changes.create',$family->slug) }}" class="management-grid"><input type="hidden" name="tree" value="{{ $tree->slug }}"><input type="hidden" name="kind" value="{{ $kind }}"><label>اختر سجلًا موجودًا لتعديله<select class="form-select" name="target" required data-remote-select data-remote-url="{{ route('management.changes.targets',[$family->slug,'tree'=>$tree->slug,'kind'=>$kind]) }}"><option value="">اختر السجل</option></select></label><button class="btn btn-outline-primary">تحميل بيانات السجل</button></form>
<h1 class="h4 mt-4">{{ $record ? 'اقتراح تعديل سجل' : 'اقتراح إضافة سجل' }}</h1><p>لا يتغير السجل المعتمد عند الإرسال. إذا تغيّر لاحقًا، يلزم تقديم مقترح جديد.</p>
<form method="post" action="{{ route('management.changes.store',[$family->slug,'tree'=>$tree->slug]) }}" class="management-grid" data-proposal-form>@csrf<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="operation" value="{{ $record ? 'update':'create' }}"><input type="hidden" name="target_id" value="{{ $record?->id }}">
@if($kind==='person') @include('management.families.partials.person-fields',['person'=>$record])
@elseif($kind==='branch') @include('management.families.partials.branch-fields',['branch'=>$record])
@else
@foreach(['person_id'=>'الابن','father_id'=>'الأب'] as $field=>$label)<label>{{ $label }}<select name="{{ $field }}" class="form-select" required data-remote-select data-remote-url="{{ route('management.lookups.people',[$family->slug,'tree'=>$tree->slug]) }}"><option value="">اختر {{ $label }}</option>@if($record)<option selected value="{{ $field==='person_id' ? $record->person_id : $record->related_person_id }}">{{ $field==='person_id' ? $record->person->full_name_ar : $record->relatedPerson->full_name_ar }}</option>@endif</select></label>@endforeach
<select name="verification_status" class="form-select">@foreach(['pending_review'=>'قيد المراجعة','verified'=>'موثق','sourced'=>'مسند','unresolved'=>'غير محسوم'] as $value=>$label)<option value="{{ $value }}" @selected($record?->verification_status===$value)>{{ $label }}</option>@endforeach</select>
@endif
<label>سبب المقترح<textarea name="reason" class="form-control" required maxlength="2000">{{ old('reason') }}</textarea></label><button class="btn btn-primary">إرسال للمراجعة</button></form></section>
@endsection
