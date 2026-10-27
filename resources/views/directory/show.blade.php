@extends('layouts.family-site')
@section('nav-current', 'directory')
@section('title', $profile->display_name)
@php($has = fn (string $field) => in_array($field, $fields, true))
@push('styles')<link href="{{ asset('css/sanad.css') }}" rel="stylesheet">@endpush
@section('content')
<section class="section-space"><div class="container">
    @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <nav class="family-breadcrumb mb-3" aria-label="مسار"><a href="{{ route('directory.index', $family->slug) }}">دليل السند</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>{{ $profile->display_name }}</span></nav>
    @if($isOwner)<p class="alert alert-info">هذا ملفك؛ تراه كاملًا. يرى الآخرون الحقول التي أتحتها فقط. <a href="{{ route('management.directory.edit', $family->slug) }}">تعديل الملف والخصوصية</a></p>@endif
    <div class="row g-4">
        <article class="col-lg-8">
            <section class="sanad-profile-section" data-directory-profile>
                <div class="sanad-profile-heading">
                    @if($has('photo') && $profile->photo_path)<img class="directory-photo" src="{{ route('directory.photo', [$family->slug, $profile->id]) }}" alt="صورة {{ $profile->display_name }}" width="88" height="88">@endif
                    <div><span class="eyebrow">{{ $family->name_ar }} · دليل السند</span><h1 class="h4 fw-bold mb-1">{{ $profile->display_name }}</h1>
                        @if($has('affiliation') && $profile->affiliation_status === 'verified' && $profile->entity)<span class="status-badge" data-field="affiliation"><i class="bi bi-patch-check"></i> {{ $profile->entity->name_ar }} — انتماء موثق</span>@endif</div>
                </div>
                <p class="small text-muted">توثيق الانتماء يخص صلتك بالعائلة، ولا يُعد اعتمادًا للمؤهلات المهنية.</p>
                @if($has('skills') && $profile->skills)<section class="mb-4"><h2>المهارات والخبرات</h2><div class="sanad-tags" data-field="skills">@foreach($profile->skills as $skill)<span>{{ $skill }}</span>@endforeach</div></section>@endif
                <h2>الملف المهني ومجالات التعاون</h2>
                <dl class="entity-facts mb-0">
                    @if($has('profession') && ($profile->profession || $profile->specialization))<div data-field="profession"><dt>المهنة والتخصص</dt><dd>{{ collect([$profile->profession, $profile->specialization])->filter()->implode(' — ') }}</dd></div>@endif
                    @if($has('sector') && $profile->sector)<div data-field="sector"><dt>القطاع</dt><dd>{{ \App\Models\MemberProfile::SECTORS[$profile->sector] }}</dd></div>@endif
                    @if($has('skills') && $profile->skills)<div data-field="skills"><dt>المهارات</dt><dd>{{ implode('، ', $profile->skills) }}</dd></div>@endif
                    @if($has('experience') && $profile->experience)<div data-field="experience"><dt>الخبرات</dt><dd>{!! nl2br(e($profile->experience)) !!}</dd></div>@endif
                    @if($has('organization') && $profile->organization)<div data-field="organization"><dt>جهة العمل</dt><dd>{{ $profile->organization }}</dd></div>@endif
                    @if($has('business') && ($profile->business_name || $profile->services))<div data-field="business"><dt>العمل التجاري والخدمات</dt><dd>{{ $profile->business_name }}@if($profile->business_category) ({{ \App\Models\MemberProfile::BUSINESS_CATEGORIES[$profile->business_category] }})@endif @if($profile->services)<br>{{ $profile->services }}@endif</dd></div>@endif
                    @if(($has('city') && $profile->city) || ($has('governorate') && $profile->governorate))<div><dt>المكان</dt><dd>@if($has('city'))<span data-field="city">{{ $profile->city }}</span>@endif @if($has('governorate'))<span data-field="governorate">{{ $profile->governorate }}</span>@endif</dd></div>@endif
                    @if($has('collaboration') && $profile->collaboration)<div data-field="collaboration"><dt>مجالات التعاون</dt><dd>{{ $profile->collaboration }}</dd></div>@endif
                    @if($has('links') && $profile->links)<div data-field="links"><dt>روابط مهنية</dt><dd>@foreach($profile->links as $link)<a href="{{ $link }}" rel="noopener nofollow ugc" target="_blank" dir="ltr">{{ parse_url($link, PHP_URL_HOST) }}</a> @endforeach</dd></div>@endif
                    @if($has('contact_email') && $profile->contact_email)<div data-field="contact_email"><dt>البريد</dt><dd dir="ltr">{{ $profile->contact_email }}</dd></div>@endif
                    @if($has('contact_phone') && $profile->contact_phone)<div data-field="contact_phone"><dt>الهاتف</dt><dd dir="ltr">{{ $profile->contact_phone }}</dd></div>@endif
                </dl>
            </section>
        </article>
        <aside class="col-lg-4 d-grid gap-3 align-content-start">
            @auth
                @if(! $isOwner && $has('messages'))
                    <section class="entity-aside sanad-profile-section" id="contact-request" data-contact-form>
                        <h2 class="h6 fw-bold">تواصل وتعاون</h2>
                        <p class="small text-muted">يصل طلبك داخل المنصة دون كشف بريدك أو هاتفك أو بياناته.</p>
                        <form method="post" action="{{ route('directory.contact', [$family->slug, $profile->id]) }}" class="d-grid gap-2">@csrf<textarea class="form-control" name="message" rows="3" maxlength="1000" aria-label="رسالة اختيارية" placeholder="رسالة اختيارية للتعريف بطلبك"></textarea>@error('message')<span class="field-error">{{ $message }}</span>@enderror<button class="btn btn-primary btn-sm">إرسال</button></form>
                    </section>
                @endif
                @unless($isOwner)
                    <details class="entity-aside"><summary class="small">الإبلاغ عن معلومات غير صحيحة أو غير لائقة</summary>
                        <form method="post" action="{{ route('directory.report', [$family->slug, $profile->id]) }}" class="d-grid gap-2 mt-2" data-report-form>@csrf<select class="form-select form-select-sm" name="reason" aria-label="سبب البلاغ">@foreach(\App\Models\DirectoryReport::REASONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select><textarea class="form-control form-control-sm" name="details" rows="2" maxlength="2000" aria-label="تفاصيل"></textarea><button class="btn btn-outline-danger btn-sm">إرسال البلاغ</button></form>
                    </details>
                @endunless
            @else
                <p class="small text-muted">سجّل الدخول بعضوية العائلة لطلب التواصل أو الإبلاغ.</p>
            @endauth
        </aside>
    </div>
</div></section>
@endsection
