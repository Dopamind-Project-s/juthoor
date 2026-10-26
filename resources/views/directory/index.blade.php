@extends('layouts.app')
@section('title', 'دليل أبناء العشيرة — '.$family->name_ar)
@section('content')
<x-page-header title="دليل أبناء العشيرة — تواصل، خبرات، أعمال وتعاون" :eyebrow="$family->name_ar" description="دليل طوعي يظهر فيه من اختار ذلك، وبالبيانات التي اختار إظهارها فقط. لا يُنشأ ملف من سجلات الشجرة، ولا تُعرض وسائل التواصل إلا بإذن صاحبها.">
    @if($canHaveProfile)<a class="btn btn-primary mt-3" href="{{ route('management.directory.edit', $family->slug) }}">{{ $ownProfile ? 'إدارة ملفي في الدليل' : 'أضف ملفك إلى الدليل' }}</a>@endif
</x-page-header>
<section class="section-space"><div class="container">
    @if($level === 'public')<p class="small text-muted">تُعرض للزوار الملفات العامة فقط؛ يرى أعضاء العائلة المسجلون ما أتاحه أصحاب الملفات لهم.</p>@endif
    <form method="get" class="filter-panel directory-filters mb-3" role="search">
        <label>بحث بالاسم أو المجال<input class="form-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100"></label>
        <label>المهنة أو التخصص<input class="form-control" name="profession" value="{{ $filters['profession'] ?? '' }}" maxlength="100"></label>
        <label>مهارة<input class="form-control" name="skill" value="{{ $filters['skill'] ?? '' }}" maxlength="60"></label>
        <label>القطاع<select class="form-select" name="sector"><option value="">الكل</option>@foreach(\App\Models\MemberProfile::SECTORS as $key => $label)<option value="{{ $key }}" @selected(($filters['sector'] ?? null) === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>المدينة<select class="form-select" name="city"><option value="">الكل</option>@foreach($cities as $city)<option @selected(($filters['city'] ?? null) === $city)>{{ $city }}</option>@endforeach</select></label>
        <label>نوع العمل<select class="form-select" name="business_category"><option value="">الكل</option>@foreach(\App\Models\MemberProfile::BUSINESS_CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(($filters['business_category'] ?? null) === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>الفخذ أو الكيان<select class="form-select" name="entity"><option value="">الكل</option>@foreach($entities as $entity)<option value="{{ $entity->id }}" @selected((string) ($filters['entity'] ?? '') === (string) $entity->id)>{{ $entity->name_ar }}</option>@endforeach</select></label>
        <button class="btn btn-primary">بحث</button>
    </form>
    <div class="row g-3">
        @forelse($profiles as $profile)
            <div class="col-md-6 col-lg-4"><x-community.directory-card :profile="$profile" :family="$family" :fields="auth()->id() === $profile->user_id ? $profile->fieldsFor('owner') : $profile->fieldsFor($level)" /></div>
        @empty
            <div class="col-12"><p class="text-muted" role="status">لا توجد ملفات مطابقة.</p></div>
        @endforelse
    </div>
    <div class="mt-3">{{ $profiles->links() }}</div>
</div></section>
@endsection
