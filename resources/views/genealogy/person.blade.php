@extends('layouts.app')
@section('title', $person->full_name_ar)
@section('content')
<section class="family-page-head"><div class="container">
    <div class="family-breadcrumb"><a href="{{ route('genealogy.tree',[$family->slug, 'tree' => $tree->slug, 'private' => request()->boolean('private') ? 1 : null]) }}">{{ $tree->name_ar }}</a><i class="bi bi-chevron-left"></i><span>صفحة شخص</span></div>
    <span class="status-badge verified">{{ request()->boolean('private') ? 'معاينة خاصة' : 'سجل تاريخي موثق' }}</span>
    <h1>{{ $person->full_name_ar }}</h1>
    <p>مسار هذا الشخص داخل شجرة {{ $family->name_ar }} الموحدة.</p>
</div></section>

<section class="section-space"><div class="container">
    <div class="privacy-note"><i class="bi bi-shield-check"></i><div><strong>خصوصية العرض</strong><p>{{ request()->boolean('private') ? 'هذه الصفحة متاحة للأعضاء المخولين فقط. لا تشارك بيانات الأحياء دون موافقتهم.' : 'لا تعرض الصفحة ملاحظات الاستيراد أو بيانات الاتصال أو أي سجل غير مصنف كتاريخي وموثق.' }}</p></div></div>

    <div class="row g-4">
        <div class="col-lg-8"><div class="product-card card-body">
            <h2 class="h4 fw-bold">المسار من جذر الشجرة</h2>
            <div class="ancestor-path">
                @foreach($ancestorPath as $ancestor)
                    <a class="{{ $ancestor->is($person) ? 'selected' : '' }}" href="{{ route('genealogy.person',[$family->slug, $ancestor->id, 'tree' => $tree->slug, 'private' => request()->boolean('private') ? 1 : null]) }}">{{ $ancestor->full_name_ar }}</a>
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
        @forelse($descendants as $descendant)<div class="col-md-6 col-lg-4"><a class="product-card card-body d-block" href="{{ route('genealogy.person',[$family->slug, $descendant->id, 'tree' => $tree->slug, 'private' => request()->boolean('private') ? 1 : null]) }}"><strong>{{ $descendant->full_name_ar }}</strong><small class="d-block text-muted">فتح المسار</small></a></div>
        @empty<p class="text-muted">لا توجد سجلات أحفاد عامة ضمن النطاق المعروض.</p>@endforelse
    </div></div>
</div></section>
@endsection
