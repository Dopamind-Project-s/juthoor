@extends('layouts.family-site')
@section('nav-current', 'directory')
@section('title', 'دليل السند — '.$family->name_ar)
@push('styles')<link href="{{ asset('css/sanad.css') }}" rel="stylesheet">@endpush
@section('content')
<section class="sanad-hero"><div class="container">
    <nav class="family-breadcrumb"><a href="{{ $portalUrl }}">{{ $family->name_ar }}</a><span aria-hidden="true">/</span><span>دليل السند</span></nav>
    <div class="sanad-hero-grid"><div><span class="eyebrow">المعرفة أقرب… والسند من أهلك</span><h1>دليل السند</h1><p class="lead">تواصل مع أبناء عشيرتك، واكتشف الخبرات والمهن والخدمات ضمن مجتمعك.</p><p class="text-muted">مساحة طوعية لـ{{ $family->name_ar }}؛ أنت تختار ما تشاركه، ومع من تشاركه.</p>
    <div class="d-flex gap-2 flex-wrap">@if($canHaveProfile)<a class="btn btn-primary" href="{{ route('management.directory.edit', $family->slug) }}">{{ $ownProfile ? 'إدارة ملفي في السند' : 'انضم إلى دليل السند' }}</a>@else<a class="btn btn-primary" href="{{ route('login') }}">سجّل الدخول للمشاركة</a>@endif<a class="btn btn-outline-primary" href="{{ route('community.entities.index', $family->slug) }}">أفخاذ العائلة</a></div></div>
    <aside class="sanad-privacy"><i class="bi bi-shield-check" aria-hidden="true"></i><h2 class="h5">اختيارك أولًا</h2><p>الظهور اختياري، ولكل معلومة جمهور تختاره. لا يُنشأ ملف من شجرة النسب، ولا يُكشف رقم هاتفك أو بريدك عند طلب التواصل.</p><span class="small">{{ $level === 'public' ? 'تتصفح الملفات والمعلومات المتاحة للعامة.' : 'تتصفح المعلومات التي أتاحها أصحابها لأعضاء العائلة.' }}</span></aside></div>
</div></section>
<section class="section-space pt-4"><div class="container">
@if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
@if($categories->isNotEmpty())<nav class="sanad-categories" aria-label="اكتشف المهن والتخصصات">@foreach($categories as $category)<a href="{{ route('directory.index', [$family->slug, 'sector' => $category->sector]) }}"><i class="bi bi-briefcase" aria-hidden="true"></i><span>{{ \App\Models\MemberProfile::SECTORS[$category->sector] }}</span><small>{{ $category->total }} ملفًا متاحًا</small></a>@endforeach</nav>@endif
<form method="get" class="sanad-search" role="search" data-sanad-search>
    <label for="sanad-q" class="fw-bold">من تبحث عنه اليوم؟</label><div class="sanad-search-line"><input id="sanad-q" class="form-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="اسم، مهنة، مهارة أو خدمة…" maxlength="100"><button class="btn btn-primary" data-sanad-search-submit>ابحث في السند</button></div>
    <details class="sanad-filter-details" @if(count(array_filter(\Illuminate\Support\Arr::except($filters, ['q', 'layout']))) > 0) open @endif><summary>تخصيص البحث <span class="small text-muted">المهنة، الفخذ، المدينة والمزيد</span></summary><div class="sanad-filter-grid">
        <label>المهنة أو التخصص<input class="form-control" name="profession" value="{{ $filters['profession'] ?? '' }}" maxlength="100"></label>
        <label>مهارة<input class="form-control" name="skill" value="{{ $filters['skill'] ?? '' }}" maxlength="60"></label>
        <label>القطاع<select class="form-select" name="sector"><option value="">الكل</option>@foreach(\App\Models\MemberProfile::SECTORS as $key => $label)<option value="{{ $key }}" @selected(($filters['sector'] ?? null) === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>المدينة<select class="form-select" name="city"><option value="">الكل</option>@foreach($cities as $city)<option @selected(($filters['city'] ?? null) === $city)>{{ $city }}</option>@endforeach</select></label>
        <label>الخدمات والأعمال<input class="form-control" name="service" value="{{ $filters['service'] ?? '' }}" maxlength="100"></label>
        <label>نوع العمل<select class="form-select" name="business_category"><option value="">الكل</option>@foreach(\App\Models\MemberProfile::BUSINESS_CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(($filters['business_category'] ?? null) === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>الفخذ أو الكيان<select class="form-select" name="entity"><option value="">الكل</option>@foreach($entities as $entity)<option value="{{ $entity->id }}" @selected((string) ($filters['entity'] ?? '') === (string) $entity->id)>{{ $entity->name_ar }}</option>@endforeach</select></label>
        <label>نطاق الانتماء<select class="form-select" name="scope">@foreach(\App\Services\Community\EntityContentScope::MODES as $key => $label)<option value="{{ $key }}" @selected(($filters['scope'] ?? 'descendants') === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>مجالات التعاون<input class="form-control" name="collaboration" value="{{ $filters['collaboration'] ?? '' }}" maxlength="100"></label>
        <label class="sanad-check"><input type="checkbox" name="verified" value="1" @checked($filters['verified'] ?? false)> انتماء عائلي موثق فقط</label>
    </div><p class="small text-muted mt-2">توثيق الانتماء للعائلة لا يعني اعتماد المؤهلات أو الخدمات المهنية.</p></details>
    <input type="hidden" name="layout" value="{{ $filters['layout'] ?? 'grid' }}"><div class="d-flex gap-3 align-items-center mt-2"><a href="{{ route('directory.index', $family->slug) }}">مسح التصفية</a><span role="status" data-sanad-loading hidden>جارٍ البحث في الملفات المتاحة…</span></div>
</form>
<div class="sanad-results-head"><div><h2 class="h4 mb-1">أبناء مجتمعك وخبراتهم</h2><p class="text-muted small mb-0" data-result-count>{{ $profiles->total() }} ملفًا مطابقًا متاحًا لك</p></div><nav class="d-flex gap-2" aria-label="طريقة عرض النتائج">@foreach(['grid' => 'بطاقات', 'list' => 'قائمة'] as $mode => $label)<a class="btn btn-sm {{ ($filters['layout'] ?? 'grid') === $mode ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ request()->fullUrlWithQuery(['layout' => $mode, 'page' => null]) }}" @if(($filters['layout'] ?? 'grid') === $mode) aria-current="true" @endif>{{ $label }}</a>@endforeach</nav></div>
<div class="sanad-results {{ ($filters['layout'] ?? 'grid') === 'list' ? 'is-list' : '' }}">
@forelse($profiles as $profile)<x-community.directory-card :profile="$profile" :family="$family" :fields="$visibleFields($profile)" />@empty<div class="sanad-empty" role="status"><i class="bi bi-search" aria-hidden="true"></i><h3 class="h5">لم نجد ملفات بهذه الخيارات</h3><p>جرّب اسمًا أقصر أو وسّع نطاق البحث. لا تظهر الملفات الخاصة أو الموقوفة.</p><a class="btn btn-outline-primary" href="{{ route('directory.index', $family->slug) }}">عرض الملفات المتاحة</a></div>@endforelse
</div><div class="mt-4">{{ $profiles->links() }}</div>
</div></section>
@endsection
@push('scripts')<script src="{{ asset('js/sanad.js') }}" defer></script>@endpush
