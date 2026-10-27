@extends('layouts.management')
@section('page-heading','ملفي في دليل السند')
@section('title','ملفي في دليل السند')
@php
    $value = fn (string $field, $default = null) => old($field, $profile->{$field} ?? $default);
    $level = fn (string $field) => old('visibility.'.$field, ($profile->field_visibility ?? [])[$field] ?? \App\Models\MemberProfile::FIELDS[$field][1]);
    $visibility = fn (string $field) => '<select class="form-select form-select-sm field-visibility" name="visibility['.$field.']" aria-label="من يرى: '.e(\App\Models\MemberProfile::FIELDS[$field][0]).'">'.collect(\App\Models\MemberProfile::LEVELS)->map(fn ($label, $key) => '<option value="'.$key.'"'.($level($field) === $key ? ' selected' : '').'>'.e($label).'</option>')->implode('').'</select>';
@endphp
@push('styles')<link href="{{ asset('css/sanad.css') }}" rel="stylesheet">@endpush
@section('content')
<section class="management-panel">
    <span class="eyebrow">دليل السند — ملفك، وخياراتك</span>
    <h1 class="h4">ملفي في دليل السند</h1>
    <p class="text-muted">الملف طوعي ومنفصل عن سجل النسب: لا يُنشأ من الشجرة، وحذفه لا يحذف حسابك ولا أي سجل في الشجرة. لكل حقل خيار ظهور مستقل، ولا يظهر أي حقل لجمهور أوسع من جمهور الملف. وسائل التواصل مخفية افتراضيًا.</p>
    @if($profile->exists)
        <p class="mb-0" data-profile-state>الحالة: <strong>{{ $profile->is_listed && ! $profile->suspended_at ? 'ظاهر في الدليل' : 'غير ظاهر' }}</strong>@if($profile->suspended_at) · <span class="text-danger">موقوف مؤقتًا من إدارة العائلة: {{ $profile->suspension_note }}</span>@endif · الانتماء: {{ \App\Models\MemberProfile::AFFILIATION_STATUSES[$profile->affiliation_status] }}@if($profile->affiliation_note) ({{ $profile->affiliation_note }})@endif · <a href="{{ route('directory.show', [$family->slug, $profile->id]) }}">عرض ملفي</a></p>
    @endif
</section>
<section class="management-panel">
    <form method="post" action="{{ route('management.directory.update', $family->slug) }}" enctype="multipart/form-data" class="directory-form" data-directory-form data-sanad-wizard>
        @csrf @method('PUT')
        <div class="sanad-completeness"><label for="sanad-complete">اكتمال الملف</label><progress id="sanad-complete" data-sanad-completeness max="6" value="0"></progress><span class="small" data-sanad-completeness-text>المهنة والتفاصيل اختيارية</span></div>
        <ol class="sanad-steps" data-sanad-step-nav hidden><li><button type="button" data-sanad-go>1. الهوية</button></li><li><button type="button" data-sanad-go>2. الانتماء</button></li><li><button type="button" data-sanad-go>3. المهنة والخبرة</button></li><li><button type="button" data-sanad-go>4. الأعمال والتعاون</button></li><li><button type="button" data-sanad-go>5. التواصل والخصوصية</button></li><li><button type="button" data-sanad-go>6. المراجعة والحفظ</button></li></ol>

        <fieldset class="sanad-step" data-sanad-step><legend tabindex="-1">1. الهوية</legend>
        <div class="directory-row"><label>الاسم المعروض<input class="form-control" name="display_name" value="{{ $value('display_name') }}" required maxlength="120">@error('display_name')<span class="field-error">{{ $message }}</span>@enderror</label><span class="small text-muted">يظهر لجمهور الملف</span></div>
        <div class="directory-row"><label>جمهور الملف<select class="form-select" name="audience"><option value="members" @selected($value('audience', 'members') === 'members')>أعضاء العائلة المسجلون</option><option value="public" @selected($value('audience') === 'public')>العامة</option></select></label></div>
        </fieldset>
        <fieldset class="sanad-step" data-sanad-step><legend tabindex="-1">2. الانتماء</legend>
        <div class="directory-row"><label>الفخذ أو الكيان (يتحقق منه مدير العائلة)<select class="form-select" name="entity_id"><option value="">دون تحديد</option>@foreach($entities as $row)<option value="{{ $row['entity']->id }}" @selected((string) $value('entity_id') === (string) $row['entity']->id)>{{ str_repeat('— ', $row['depth']) }}{{ $row['entity']->name_ar }}</option>@endforeach</select>@error('entity_id')<span class="field-error">{{ $message }}</span>@enderror</label>{!! $visibility('affiliation') !!}</div>
        </fieldset>
        <fieldset class="sanad-step" data-sanad-step><legend tabindex="-1">3. المهنة والخبرة</legend>
        <div class="directory-row"><label>المهنة<input class="form-control" name="profession" value="{{ $value('profession') }}" maxlength="255"></label><label>التخصص<input class="form-control" name="specialization" value="{{ $value('specialization') }}" maxlength="255"></label>{!! $visibility('profession') !!}</div>
        <div class="directory-row"><label>القطاع<select class="form-select" name="sector"><option value="">—</option>@foreach(\App\Models\MemberProfile::SECTORS as $key => $label)<option value="{{ $key }}" @selected($value('sector') === $key)>{{ $label }}</option>@endforeach</select></label>{!! $visibility('sector') !!}</div>
        <div class="directory-row"><label>المهارات (مفصولة بفواصل)<input class="form-control" name="skills" value="{{ old('skills', implode('، ', $profile->skills ?? [])) }}" maxlength="1000"></label>{!! $visibility('skills') !!}</div>
        <div class="directory-row"><label>الخبرات<textarea class="form-control" name="experience" rows="3" maxlength="2000">{{ $value('experience') }}</textarea></label>{!! $visibility('experience') !!}</div>
        <div class="directory-row"><label>جهة العمل<input class="form-control" name="organization" value="{{ $value('organization') }}" maxlength="255"></label>{!! $visibility('organization') !!}</div>
        </fieldset>
        <fieldset class="sanad-step" data-sanad-step><legend tabindex="-1">4. الأعمال والتعاون</legend>
        <div class="directory-row"><label>العمل التجاري<input class="form-control" name="business_name" value="{{ $value('business_name') }}" maxlength="255"></label><label>نوعه<select class="form-select" name="business_category"><option value="">—</option>@foreach(\App\Models\MemberProfile::BUSINESS_CATEGORIES as $key => $label)<option value="{{ $key }}" @selected($value('business_category') === $key)>{{ $label }}</option>@endforeach</select></label><label>الخدمات<textarea class="form-control" name="services" rows="2" maxlength="2000">{{ $value('services') }}</textarea></label>{!! $visibility('business') !!}</div>
        <div class="directory-row"><label>المدينة<input class="form-control" name="city" value="{{ $value('city') }}" maxlength="120"></label>{!! $visibility('city') !!}</div>
        <div class="directory-row"><label>المحافظة<input class="form-control" name="governorate" value="{{ $value('governorate') }}" maxlength="120"></label>{!! $visibility('governorate') !!}</div>
        <div class="directory-row"><label>روابط مهنية (سطر لكل رابط، حتى 5)<textarea class="form-control" name="links" rows="2" dir="ltr" maxlength="2000">{{ old('links', implode("\n", $profile->links ?? [])) }}</textarea>@error('links')<span class="field-error">{{ $message }}</span>@enderror</label>{!! $visibility('links') !!}</div>
        <div class="directory-row"><label>مجالات التعاون التي ترحب بها<textarea class="form-control" name="collaboration" rows="2" maxlength="2000">{{ $value('collaboration') }}</textarea></label>{!! $visibility('collaboration') !!}</div>
        </fieldset>
        <fieldset class="sanad-step" data-sanad-step><legend tabindex="-1">5. التواصل والخصوصية</legend>
        <div class="directory-row"><label>صورة الملف (JPEG أو PNG، تُحذف بياناتها الوصفية)<input class="form-control" type="file" name="photo" accept=".jpg,.jpeg,.png">@error('file')<span class="field-error">{{ $message }}</span>@enderror @error('photo')<span class="field-error">{{ $message }}</span>@enderror</label>@if($profile->photo_path)<label class="flex-row align-items-center gap-2"><input type="checkbox" name="remove_photo" value="1"> إزالة الصورة</label>@endif{!! $visibility('photo') !!}</div>
        <div class="directory-row"><label>بريد التواصل<input class="form-control" type="email" name="contact_email" value="{{ $value('contact_email') }}" dir="ltr">@error('contact_email')<span class="field-error">{{ $message }}</span>@enderror</label>{!! $visibility('contact_email') !!}</div>
        <div class="directory-row"><label>هاتف التواصل<input class="form-control" name="contact_phone" value="{{ $value('contact_phone') }}" dir="ltr">@error('contact_phone')<span class="field-error">{{ $message }}</span>@enderror</label>{!! $visibility('contact_phone') !!}</div>
        <div class="directory-row"><span>استقبال طلبات تواصل عبر المنصة (دون كشف وسائلك)</span>{!! $visibility('messages') !!}</div>
        </fieldset>
        <fieldset class="sanad-step" data-sanad-step><legend tabindex="-1">6. المراجعة والحفظ</legend>
        <label class="flex-row align-items-center gap-2 mt-3"><input type="hidden" name="is_listed" value="0"><input type="checkbox" name="is_listed" value="1" @checked(old('is_listed', $profile->is_listed))> أوافق على إظهار ملفي في الدليل وفق الخيارات أعلاه</label>
        <div class="sanad-preview" data-sanad-preview aria-live="polite"><p>راجع جمهور كل حقل قبل الحفظ. يمكنك حفظ الملف دون نشره.</p></div>
        <button class="btn btn-primary mt-3" type="submit">حفظ</button>
        </fieldset>
        <div class="sanad-wizard-actions" data-sanad-controls hidden><button type="button" class="btn btn-outline-primary" data-sanad-previous>السابق</button><button type="button" class="btn btn-primary" data-sanad-next>التالي</button></div>
    </form>
    @if($profile->exists)
        <form method="post" action="{{ route('management.directory.destroy', $family->slug) }}" class="mt-3" data-confirm="الانسحاب وحذف ملف الدليل نهائيًا؟">@csrf @method('DELETE')<button class="btn btn-outline-danger" data-delete-profile>الانسحاب وحذف ملفي من الدليل</button></form>
    @endif
</section>
@endsection

@push('scripts')<script src="{{ asset('js/sanad.js') }}" defer></script>@endpush
