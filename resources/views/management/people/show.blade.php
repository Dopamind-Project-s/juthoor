@extends('layouts.management')
@section('title',$person->display_name)
@section('breadcrumb',$family->name_ar.' / الأشخاص')
@section('page-heading',$person->display_name)
@section('content')
<div class="d-flex flex-wrap gap-2 mb-4">
@if($canEdit)
<a class="btn btn-primary" data-add-child href="{{ route('management.records.create',[$family->slug,'people','tree'=>$tree->slug,'father_id'=>$person->id]) }}">إضافة ابن / ابنة</a>
<a class="btn btn-outline-primary" href="{{ route('management.records.edit',[$family->slug,'people',$person->id,'tree'=>$tree->slug]) }}">تعديل الشخص والأب والفروع</a>
@endif
<a class="btn btn-outline-primary" href="{{ route('genealogy.tree',[$family->slug,'tree'=>$tree->slug,'private'=>1]) }}">عرض الشجرة المحدّثة</a>
@can('propose',$family)<a class="btn btn-outline-primary" href="{{ route('management.changes.create',[$family->slug,'tree'=>$tree->slug,'kind'=>'relationship']) }}">اقتراح تغيير علاقة للمراجعة</a>@endcan
</div>
<section class="management-panel"><h2 class="h5">الفروع التي يؤسسها هذا الشخص</h2>
@forelse($person->foundedBranches as $foundedBranch)
<div class="d-flex flex-wrap gap-2 mb-2"><strong>{{ $foundedBranch->name_ar }}</strong>
@if($foundedBranch->is_active)<a href="{{ route('genealogy.branch',[$family->slug,$foundedBranch->slug,'tree'=>$tree->slug,'private'=>1]) }}">تركيز الفرع</a>@endif
@if($canEdit)<a href="{{ route('management.records.edit',[$family->slug,'branches',$foundedBranch->id,'tree'=>$tree->slug]) }}">تعديل / حذف محمي</a>@endif</div>
@empty<p class="text-muted">لا يؤسس فروعًا بعد. يمكن إنشاء فرع من نموذج تعديل الشخص.</p>@endforelse
</section>
<div class="row g-4"><div class="col-lg-8"><section class="management-panel"><span class="status-badge">{{ $person->verification_status }}</span><h1 class="h3 mt-2">{{ $person->display_name }}</h1>@if($person->alternate_names)<p class="text-muted">تهجئات بديلة مسجلة: {{ implode('، ', $person->alternate_names) }}</p>@endif<x-person-descriptors :person="$person" /><dl class="row"><dt class="col-4">المفتاح</dt><dd class="col-8">{{ $person->canonical_key ?: 'غير محدد' }}</dd><dt class="col-4">الحالة</dt><dd class="col-8">{{ $person->status }}</dd>@include('management.partials.person-private-fields',['personFields'=>app(\App\Services\Genealogy\PersonPrivacy::class)->fields($person, auth()->user())])</dl>@if($canEdit)<details class="management-form"><summary>تعديل السجل</summary><form method="post" action="{{ route('management.people.update',[$family->slug,$person->id, 'tree' => $tree->slug]) }}" class="management-grid">@csrf @method('PUT') @include('management.families.partials.person-fields',['person'=>$person])<button class="btn btn-primary">حفظ</button></form></details>@endif</section></div><aside class="col-lg-4"><section class="management-panel"><h2 class="h5">العلاقات</h2>@forelse($person->outgoingRelationships->where('relationship_type','father')->where('is_primary',true) as $edge)<p>الأب: <a href="{{ route('management.people.show',[$family->slug,$edge->relatedPerson->id, 'tree' => $tree->slug]) }}">{{ $edge->relatedPerson->display_name }}</a></p>@empty<p class="text-muted">{{ $tree->root_person_id===$person->id ? 'جذر الشجرة المحدد.' : 'الأب غير معروف / غير مرتبط بعد — متاح للمراجعة.' }}</p>@endforelse<h2 class="h5 mt-4">الأبناء</h2>@forelse($person->incomingRelationships->where('relationship_type','father')->where('is_primary',true) as $edge)<a class="d-block" href="{{ route('management.people.show',[$family->slug,$edge->person->id, 'tree' => $tree->slug]) }}">{{ $edge->person->display_name }}</a>@empty<p class="text-muted">لا توجد سجلات أبناء.</p>@endforelse</section></aside></div><section class="management-panel" id="sources"><h2 class="h5">المصادر والاستشهادات</h2>@foreach($person->sources->where('family_id',$family->id) as $legacySource)<p><a href="{{ route('management.sources.show',[$family->slug,$legacySource->id,'tree'=>$tree->slug]) }}">{{ $legacySource->title }}</a> · رابط مصدر قائم</p>@endforeach
@php
$allCitations = $person->citations->concat($person->outgoingRelationships->flatMap->citations);
@endphp
@foreach ($allCitations as $citation)
<p><a href="{{ route('management.sources.show',[$family->slug,$citation->source_id,'tree'=>$tree->slug]) }}">{{ $citation->source->title }}</a> · {{ $citation->locator }} · {{ $citation->field_key==='father'?'علاقة الأبوة':($citation->field_key==='person'?'الشخص':__('genealogy.fields.'.$citation->field_key)) }}</p>@endforeach<a href="{{ route('management.sources.index',[$family->slug,'tree'=>$tree->slug]) }}">مكتبة المصادر</a></section>
@include('management.people.facts')
@endsection
