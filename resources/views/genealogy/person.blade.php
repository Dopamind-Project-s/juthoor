@extends('layouts.app')
@section('title', $person->display_name)
@section('content')
<section class="family-page-head"><div class="container">
    <div class="family-breadcrumb"><a href="{{ route('genealogy.tree',[$family->slug, 'tree' => $tree->slug, 'private' => request()->boolean('private') ? 1 : null]) }}">{{ $tree->name_ar }}</a><i class="bi bi-chevron-left"></i><span>صفحة شخص</span></div>
    <span class="status-badge verified">{{ request()->boolean('private') ? 'معاينة خاصة' : (in_array($person->verification_status,['verified','sourced']) ? 'سجل تاريخي مراجع' : 'رواية مقدمة؛ قيد المراجعة') }}</span>
    <h1>{{ $person->display_name }}</h1><x-person-descriptors :person="$person" />
    @if($person->alternate_names)<p class="mb-1">تهجئات بديلة مسجلة: {{ implode('، ', $person->alternate_names) }}</p>@endif
    @if($personFields || request()->boolean('private'))<dl class="person-public-facts">@if(request()->boolean('private'))<dt>حالة الحياة</dt><dd>{{ \App\Models\Person::LIVING_STATUS_LABELS[$person->living_status] }}</dd>@endif @foreach($personFields as $field)<dt>{{ $field['label'] }}</dt><dd>{{ $field['value'] instanceof \DateTimeInterface ? $field['value']->format('Y-m-d') : (['male'=>'ذكر','female'=>'أنثى'][$field['value']] ?? $field['value']) }}</dd>@endforeach</dl>@endif
    <p>مسار هذا الشخص داخل شجرة {{ $family->name_ar }} الموحدة.</p>
</div></section>

<section class="section-space"><div class="container">
    <div class="privacy-note"><i class="bi bi-shield-check"></i><div><strong>خصوصية العرض</strong><p>{{ request()->boolean('private') ? 'هذه الصفحة متاحة للأعضاء المخولين فقط. لا تشارك بيانات الأحياء دون موافقتهم.' : 'لا تعرض الصفحة ملاحظات الاستيراد أو بيانات الاتصال وتوضح حالة مراجعة كل سجل تاريخي منشور.' }}</p></div></div>

    @if(auth()->user()?->hasVerifiedEmail() && auth()->user()?->canEditFamily($family))
<div class="d-flex gap-2 my-3"><a class="btn btn-primary" href="{{ route('management.records.create',[$family->slug,'people','tree'=>$tree->slug,'father_id'=>$person->id]) }}">إضافة ابن / ابنة</a><a class="btn btn-outline-primary" href="{{ route('management.records.edit',[$family->slug,'people',$person->id,'tree'=>$tree->slug]) }}">تعديل الأب والفروع</a></div>
@endif
<div class="product-card card-body mb-4"><h2 class="h5">الأب والأبناء المباشرون</h2>
@if($father)<p>الأب: <a href="{{ route('genealogy.person',[$family->slug,$father->id,'tree'=>$tree->slug,'private'=>request()->boolean('private') ? 1 : null]) }}">{{ $father->display_name }}</a></p>
@else<p>{{ $tree->root_person_id===$person->id ? 'جذر الشجرة' : 'لا توجد علاقة أبوة متاحة في هذا العرض.' }}</p>@endif
@forelse ($directChildren as $child)
<a class="d-block" href="{{ route('genealogy.person',[$family->slug,$child->id,'tree'=>$tree->slug,'private'=>request()->boolean('private') ? 1 : null]) }}">{{ $child->display_name }}</a>
@empty
<p>لا توجد سجلات أبناء متاحة.</p>
@endforelse

</div>
<div class="row g-4">
        <div class="col-lg-8"><div class="product-card card-body">
            <h2 class="h4 fw-bold">المسار من جذر الشجرة</h2>
            <div class="ancestor-path">
                @foreach($ancestorPath as $ancestor)
                    <a class="{{ $ancestor->is($person) ? 'selected' : '' }}" href="{{ route('genealogy.person',[$family->slug, $ancestor->id, 'tree' => $tree->slug, 'private' => request()->boolean('private') ? 1 : null]) }}">{{ $ancestor->display_name }}</a>
                    @unless($loop->last)<i class="bi bi-arrow-down"></i>@endunless
                @endforeach
            </div>
        </div></div>
        <aside class="col-lg-4"><div class="product-card card-body">
            <h2 class="h5 fw-bold">بيانات السجل العام</h2>
            <dl class="mb-0"><dt>الحالة</dt><dd>{{ $person->status }}</dd><dt>التوثيق</dt><dd>{{ ['verified'=>'موثق','sourced'=>'مسند إلى مصدر','pending_review'=>'قيد المراجعة','unresolved'=>'غير محسوم'][$person->verification_status] }}</dd></dl>
            @foreach($foundedBranches as $branch)<a class="btn btn-primary w-100 mt-3" href="{{ route('genealogy.branch',[$family->slug, $branch->slug, 'tree' => $tree->slug, 'private' => request()->boolean('private') ? 1 : null]) }}">تركيز على فرع {{ $branch->name_ar }}</a>@endforeach
        </div></aside>
    </div>

    <div class="mt-5"><h2 class="h4 fw-bold">الأحفاد المتاحون للعامة حتى جيلين</h2><div class="row g-3 mt-1">
        @forelse($descendants as $descendant)<div class="col-md-6 col-lg-4"><a class="product-card card-body d-block" href="{{ route('genealogy.person',[$family->slug, $descendant->id, 'tree' => $tree->slug, 'private' => request()->boolean('private') ? 1 : null]) }}"><strong>{{ $descendant->display_name }}</strong><x-person-descriptors :person="$descendant" /><small class="d-block text-muted">فتح المسار</small></a></div>
        @empty<p class="text-muted">لا توجد سجلات أحفاد عامة ضمن النطاق المعروض.</p>@endforelse
    </div></div>
</div></section>
@endsection
