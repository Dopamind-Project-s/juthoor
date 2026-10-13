@extends('layouts.management')
@section('page-heading','معاينة استرجاع تغيير')
@section('content')
<section class="management-panel"><h1 class="h4">استرجاع العملية {{ $log->id }}</h1><p>لن يُحذف سجل التدقيق. تُعاد فحوص الحالة والاعتماديات وسلامة الأنساب عند التأكيد.</p>
@if($blocked)<div class="alert alert-warning">{{ $blocked }}</div>@else
<p>{{ $context['operation']==='delete' ? 'الأثر: حذف السجل الذي أنشأته هذه العملية إذا لم يرتبط باعتماديات جديدة.' : 'الأثر: إعادة بيانات السجل إلى قيمها قبل العملية.' }}</p>
@include('management.changes.diff',['before'=>$log->after,'after'=>$context['operation']==='delete'?[]:$log->before])
<form method="post" class="workflow-confirmation" action="{{ route('management.audit.revert.store',$log->id) }}">@csrf<label>سبب الاسترجاع<textarea name="reason" class="form-control" required maxlength="2000"></textarea></label><label class="d-block mt-3"><input type="checkbox" name="confirm" value="1" required> راجعت الأثر وأؤكد الاسترجاع</label><button class="btn btn-outline-danger mt-3">تأكيد الاسترجاع</button></form>
@endif<a class="btn btn-outline-primary mt-3" href="{{ route('management.audit.show',$log->id) }}">تفاصيل العملية الأصلية</a></section>
@endsection
