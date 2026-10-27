@extends('layouts.management')
@section('title', $person ? 'تعديل شخص' : 'إضافة شخص')
@section('page-heading', $family->name_ar.' / الأشخاص')
@section('content')
@php
    $statusLabels = ['verified' => 'موثق', 'sourced' => 'مسند إلى مصدر', 'pending_review' => 'قيد المراجعة', 'unresolved' => 'غير محسوم'];
@endphp
<div class="d-flex flex-wrap justify-content-between gap-3 mb-3"><h1 class="h3">{{ $person ? 'تعديل: '.$person->display_name : 'إضافة شخص إلى الشجرة' }}</h1>
<div class="d-flex gap-2">@if($person)<a class="btn btn-outline-primary" href="{{ route('management.people.show',[$family->slug,$person->id,'tree'=>$tree->slug]) }}">صفحة الشخص</a>@endif<a class="btn btn-outline-primary" href="{{ route('management.sections.index',[$family->slug,'people','tree'=>$tree->slug]) }}">العودة للقائمة</a></div></div>

@if(!$person)
{{-- Guided creation: works as one long form without JavaScript; steps are an enhancement. --}}
<section class="management-panel">
<ol class="guided-steps" data-step-nav aria-label="خطوات الإضافة">
@foreach(['الهوية والأب','التحقق من التكرار','معلومات اختيارية','المراجعة والحفظ'] as $index=>$label)<li><button type="button" data-step-target="{{ $index+1 }}">{{ $label }}</button></li>@endforeach
</ol>
<form method="post" action="{{ route('management.people.store',[$family->slug,'tree'=>$tree->slug]) }}" class="guided-form" data-guided-person data-candidates-url="{{ route('management.people.candidates',[$family->slug,'tree'=>$tree->slug]) }}" data-can-publish="{{ $canPublish ? 1 : 0 }}" novalidate>@csrf
<fieldset data-step="1" class="management-grid"><legend class="h5">١. الهوية والأب</legend>
@if($fatherContext)
<div class="placement-card" data-placement-card><span class="small text-muted d-block">سيُضاف ابنًا لـ</span><strong>{{ $fatherContext['name'] }}</strong> <span class="text-muted">{{ $fatherContext['lineage'] }}</span>
<div class="small mt-1">الفرع المشتق من سلسلة الأبوة: {{ $fatherContext['branches'] ? implode(' ← ', $fatherContext['branches']) : 'خارج الفروع المسجلة' }} · توثيق الأب: {{ $statusLabels[$fatherContext['verification']] ?? $fatherContext['verification'] }}</div>
<div class="small text-muted">أبناؤه المسجلون: {{ $fatherContext['children'] ? implode('، ', $fatherContext['children']) : 'لا يوجد بعد' }}</div>
<button type="button" class="btn btn-link btn-sm px-0" data-change-father>تغيير الأب أو إلغاء الاختيار</button></div>
@endif
@include('management.families.partials.person-identity-fields',['person'=>null,'guided'=>true])
<div @if($fatherContext) data-father-picker hidden @endif>@include('management.families.partials.person-father-fields',['person'=>null])</div>
<p class="text-muted small mb-0">الفرع والعشيرة لا يُختاران يدويًا: يُحسبان من علاقة الأبوة المعتمدة.</p>
</fieldset>

<fieldset data-step="2"><legend class="h5">٢. التحقق من التكرار قبل الحفظ</legend>
<p class="text-muted">يُبحث بالاسم المطبّع (الهمزات والتشكيل) وبالتهجئات المسجلة، مع مقارنة الأب وسلسلة الأسلاف. لا يُدمج أي سجل تلقائيًا.</p>
<div role="status" aria-live="polite" data-candidates-status>@if($errors->has('confirm_distinct')){{ $errors->first('confirm_distinct') }}@else سيظهر هنا ما يشبه الاسم المدخل.@endif</div>
<div class="candidate-list mt-2" data-candidates></div>
<label class="duplicate-confirmation mt-2" data-confirm-distinct @unless($errors->has('confirm_distinct') || old('confirm_distinct')) hidden @endunless><span><input type="checkbox" name="confirm_distinct" value="1" @checked(old('confirm_distinct'))> راجعت السجلات المطابقة وأؤكد أنه شخص مختلف</span></label>
</fieldset>

<fieldset data-step="3" class="management-grid"><legend class="h5">٣. معلومات اختيارية</legend>
<p class="text-muted mb-0">كلها اختيارية. حالة الحياة غير المعروفة لا تعني الوفاة، وصاحبها لا يظهر للعامة. المصادر والروايات تضاف من صفحة الشخص بعد الحفظ.</p>
@include('management.families.partials.person-history-fields',['person'=>null])
@include('management.families.partials.person-publication-fields',['person'=>null])
@include('management.families.partials.person-founder-fields',['person'=>null])
</fieldset>

<fieldset data-step="4"><legend class="h5">٤. المراجعة والحفظ</legend>
<div class="review-summary management-panel mb-3" data-review-summary><p class="text-muted mb-0">يظهر هنا ملخص التغيير قبل الحفظ.</p></div>
<p class="impact-note" data-publication-note>{{ $canPublish ? 'تملك صلاحية النشر: اختيار «موثق» أو «مسند» مع حالة «متوفى» يجعل السجل ظاهرًا للعامة؛ غير ذلك يبقى خاصًا.' : 'يُحفظ السجل «قيد المراجعة» ولا يظهر للعامة؛ اعتماده ونشره من صلاحية مراجع الأنساب.' }}</p>
@if($fatherContext)<label class="d-block mb-2"><input type="checkbox" name="add_another" value="1" @checked(old('add_another'))> بعد الحفظ: إضافة ابن آخر للأب نفسه</label>@endif
<button class="btn btn-primary">حفظ الشخص</button>
</fieldset>
<div class="guided-step-actions" data-step-actions hidden><button type="button" class="btn btn-outline-primary" data-step-prev>السابق</button><button type="button" class="btn btn-primary" data-step-next>التالي</button></div>
</form>
</section>
@else
{{-- Sectioned editing with read-only states that follow the publication and shared-ancestor rules. --}}
<nav class="person-sections-nav" aria-label="أقسام الشخص">@foreach(['identity'=>'الهوية','genealogy'=>'النسب والعلاقات','historical'=>'البيانات التاريخية','sources'=>'المصادر والأدلة','privacy'=>'الخصوصية والنشر','claims'=>'الروايات البديلة','history'=>'السجل والتدقيق'] as $anchor=>$label)<a href="#person-{{ $anchor }}">{{ $label }}</a>@endforeach</nav>
@if($readOnlyReason === 'published')<div class="readonly-note mb-3" role="status"><strong>سجل منشور للعامة.</strong> التعديل المباشر من صلاحية من يملك النشر؛ قدّم مقترح تصحيح يراجعه مراجع الأنساب. @if($proposeUrl)<a class="btn btn-sm btn-primary ms-2" href="{{ $proposeUrl }}">اقتراح تصحيح</a>@endif</div>@endif
@if($readOnlyReason === 'shared')<div class="readonly-note mb-3" role="status"><strong>جد مشترك</strong> مع: {{ $sharedFamilies->pluck('name_ar')->implode('، ') }}. تغيير وقائعه يعتمده مشرف المنصة بعد مراجعة العائلات المتأثرة. @if($proposeUrl)<a class="btn btn-sm btn-primary ms-2" href="{{ $proposeUrl }}">اقتراح تصحيح</a>@endif</div>@endif
@if($pendingChanges)<p class="alert alert-info">على هذا الشخص {{ $pendingChanges }} مقترح بانتظار المراجعة. <a href="{{ route('management.changes.index',[$family->slug,'tree'=>$tree->slug]) }}">قائمة المراجعة</a></p>@endif
<form method="post" action="{{ route('management.people.update',[$family->slug,$person->id,'tree'=>$tree->slug]) }}" data-person-edit data-original-father="{{ $impact['father_id'] }}" data-descendants="{{ $impact['descendants'] }}">@csrf @method('PUT')
<fieldset @disabled($readOnlyReason)>
<section class="management-panel person-section" id="person-identity"><h2 class="h5">الهوية</h2><div class="management-grid">@include('management.families.partials.person-identity-fields')</div>@if($person->alternate_names)<p class="small text-muted mb-0">تهجئات مسجلة: {{ implode('، ', $person->alternate_names) }} — تُعدّل عبر الروايات البديلة.</p>@endif</section>
<section class="management-panel person-section" id="person-genealogy"><h2 class="h5">النسب والعلاقات</h2>
<div class="placement-card mb-3"><div>يعتمد على هذا الشخص: <strong>{{ $impact['descendants'] }}</strong> من الذرية المسجلة.</div>
<div class="small">الفروع المشتقة من سلسلة أبوته: {{ $impact['member_of'] ? implode(' ← ', $impact['member_of']) : 'خارج الفروع المسجلة' }}@if($impact['founded']) · يؤسس: {{ implode('، ', $impact['founded']) }}@endif</div>
@if($impact['shared_families'])<div class="small">جد مشترك مع: {{ implode('، ', $impact['shared_families']) }}</div>@endif</div>
<p class="impact-note" data-father-impact hidden>تغيير الأب ينقل هذا الشخص و{{ $impact['descendants'] }} من ذريته إلى موضع آخر، وقد يغيّر عضوية الفروع{{ $impact['founded'] ? ' ومنها الفروع التي يؤسسها' : '' }}. تُفحص الدورات والأبوة المزدوجة وصلاحية الفروع عند الحفظ.</p>
<div class="management-grid">@include('management.families.partials.person-father-fields')@include('management.families.partials.person-founder-fields')</div></section>
<section class="management-panel person-section" id="person-historical"><h2 class="h5">البيانات التاريخية</h2><div class="management-grid">@include('management.families.partials.person-history-fields')</div></section>
<section class="management-panel person-section" id="person-privacy"><h2 class="h5">الخصوصية والنشر</h2><p class="small text-muted">الحالة الحالية: {{ $statusLabels[$person->verification_status] ?? $person->verification_status }} · {{ $isPublic ? 'ظاهر للعامة' : 'غير ظاهر للعامة' }}.</p><div class="management-grid">@include('management.families.partials.person-publication-fields')</div></section>
@unless($readOnlyReason)<div class="d-flex gap-2 mb-4"><button class="btn btn-primary">حفظ التعديلات</button></div>@endunless
</fieldset>
</form>
<section class="management-panel person-section" id="person-sources"><h2 class="h5">المصادر والأدلة</h2><p class="mb-2">{{ $citationCount }} مرجع واستشهاد مرتبط.</p><a class="btn btn-sm btn-outline-primary" href="{{ route('management.people.show',[$family->slug,$person->id,'tree'=>$tree->slug]) }}#sources">إدارة المصادر والاستشهادات</a></section>
<section class="management-panel person-section" id="person-claims"><h2 class="h5">الروايات البديلة</h2><p class="mb-2">{{ $assertionCount }} رواية مسجلة. الروايات المختلفة لا تغيّر الشجرة قبل اعتمادها.</p><a class="btn btn-sm btn-outline-primary" href="{{ route('management.people.show',[$family->slug,$person->id,'tree'=>$tree->slug]) }}#facts">عرض الروايات وإضافتها</a></section>
<section class="management-panel person-section" id="person-history"><h2 class="h5">السجل والتدقيق</h2><p class="mb-2">كل تعديل محفوظ مع القيم السابقة ومن قام به.</p><a class="btn btn-sm btn-outline-primary" href="{{ route('management.sections.index',[$family->slug,'audit','tree'=>$tree->slug,'person'=>$person->id]) }}">سجل التدقيق</a> @if($proposeUrl)<a class="btn btn-sm btn-outline-primary" href="{{ $proposeUrl }}">اقتراح تصحيح</a>@endif</section>
@endif
<script src="{{ asset('js/person-form.js') }}" defer></script>
@endsection
