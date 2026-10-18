@extends('layouts.management')
@section('page-heading','تفاصيل المصدر والاستشهادات')
@section('content')
<section class="management-panel"><h1 class="h4">{{ $source->title }}</h1><p>المرجع: {{ $source->reference ?: '—' }} · {{ ['pending_review'=>'قيد المراجعة','verified'=>'موثق','sourced'=>'مسند','unresolved'=>'غير محسوم'][$source->verification_status] ?? 'غير محسوم' }}</p><p>{{ $source->notes }}</p>@if($source->url)<a href="{{ $source->url }}" rel="noopener noreferrer" target="_blank">رابط المصدر</a>@endif @if($canDownload)<a class="btn btn-outline-primary" href="{{ route('management.sources.download',[$family->slug,$source->id,'tree'=>$tree->slug]) }}">تنزيل الملف الخاص</a>@endif
@if($source->file_path && !$canDownload)<p>المرفق السابق ليس في مخزن الملفات الخاصة. يمكن للمحرر رفع نسخة خاصة دون تغيير مرجع المصدر.</p>@endif
@can('manageSources',$family)<details class="management-form mt-3"><summary>تعديل المصدر</summary><form method="post" enctype="multipart/form-data" action="{{ route('management.sources.update',[$family->slug,$source->id,'tree'=>$tree->slug]) }}" class="management-grid">@csrf @method('PUT') @include('management.sources.fields')<button class="btn btn-primary">تحديث المصدر</button></form></details>
<form method="post" action="{{ route('management.sources.destroy',[$family->slug,$source->id,'tree'=>$tree->slug]) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger mt-3">حذف المصدر غير المرتبط</button></form>@endcan</section>
<section class="management-panel"><h2 class="h5">استشهادات {{ $tree->name_ar }}</h2>
@can('manageSources',$family)
@php($kind=request('citation_kind')==='relationship'?'relationship':'person')
<div class="management-tabs"><a href="{{ route('management.sources.show',[$family->slug,$source->id,'tree'=>$tree->slug,'citation_kind'=>'person']) }}">ربط شخص أو معلومة</a><a href="{{ route('management.sources.show',[$family->slug,$source->id,'tree'=>$tree->slug,'citation_kind'=>'relationship']) }}">ربط علاقة أبوة</a></div>
<form method="post" action="{{ route('management.sources.cite',[$family->slug,$source->id,'tree'=>$tree->slug]) }}" class="management-grid" data-citation-form>@csrf<input type="hidden" name="target_type" value="{{ $kind }}"><label>السجل المدعوم<select name="target_id" class="form-select" required data-remote-select data-remote-url="{{ route('management.changes.targets',[$family->slug,'tree'=>$tree->slug,'kind'=>$kind]) }}"><option value="">اختر السجل</option></select></label>
<label>المعلومة المدعومة<select name="field_key" class="form-select">@foreach($kind==='person'?['person'=>'الشخص عمومًا','full_name_ar'=>'الاسم الكامل','birth_year'=>'سنة الميلاد','death_year'=>'سنة الوفاة','notes'=>'الملاحظات']:['father'=>'علاقة الأبوة'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
@include('management.sources.citation-fields',['citation'=>null])<button class="btn btn-primary">ربط الاستشهاد</button></form>@endcan
<div class="management-list">
@forelse($citations as $citation)
<article><div>
<strong>
@if($citation->person)
    {{ $citation->person->display_name }}
@elseif($citation->relationship)
    {{ $citation->relationship->person->display_name }} ← {{ $citation->relationship->relatedPerson->display_name }}
@else
    سجل مصدر ينتظر حسم الهوية والأبوة
@endif
</strong>
<small>{{ $citation->field_key === 'source_observation' ? 'سجل مصدر غير محسوم' : ($citation->field_key === 'father' ? 'علاقة الأبوة' : 'معلومة عن الشخص') }} · {{ $citation->locator }} · {{ ['pending_review'=>'قيد المراجعة','verified'=>'موثق','sourced'=>'مسند','unresolved'=>'غير محسوم'][$citation->verification_status] }}</small>
<p>{{ $citation->notes }}</p>
@if($citation->person_id || $citation->person_relationship_id)
<a href="{{ route('management.changes.create',[$family->slug,'tree'=>$tree->slug,'kind'=>$citation->person_id?'person':'relationship','target'=>$citation->person_id ?? $citation->person_relationship_id]) }}">اقتراح تعديل أو مراجعة السجل</a>
@else
<small>لا ينشئ هذا الاستشهاد شخصاً أو علاقة. يلزم حسم الهوية والأبوة من المصدر قبل تقديم مقترح في مسار المراجعة.</small>
@endif
@if($citation->url)
<a href="{{ $citation->url }}" target="_blank" rel="noopener noreferrer">رابط الاستشهاد</a>
@endif
</div>
@can('manageSources',$family)
<details><summary>تعديل الاستشهاد</summary><form method="post" action="{{ route('management.sources.citation.update',[$family->slug,$source->id,$citation->id,'tree'=>$tree->slug]) }}" class="management-grid">
@csrf
@method('PUT')
@include('management.sources.citation-fields')
<button class="btn btn-primary">حفظ الاستشهاد</button></form></details>
<form method="post" action="{{ route('management.sources.citation.destroy',[$family->slug,$source->id,$citation->id,'tree'=>$tree->slug]) }}">
@csrf
@method('DELETE')
<button class="btn btn-outline-danger">إزالة الربط</button></form>
@endcan
</article>
@empty
<p>لا توجد استشهادات في هذه الشجرة.</p>
@endforelse
</div>{{ $citations->links() }}</section>
@endsection
