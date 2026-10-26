@php
    $zone = config('community.timezone');
    $local = fn (string $field) => old($field, $record->{$field}?->setTimezone($zone)->format('Y-m-d\TH:i'));
@endphp
<label>التصنيف<select class="form-select" name="category" data-news-category>@foreach(\App\Models\NewsPost::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected($v('category', 'news') === $key)>{{ $label }}</option>@endforeach</select>@error('category')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>العنوان<input class="form-control" name="title" value="{{ $v('title') }}" required maxlength="255">@error('title')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>الملخص<textarea class="form-control" name="summary" rows="2" maxlength="500">{{ $v('summary') }}</textarea></label>
<label>النص<textarea class="form-control" name="body" rows="8" required maxlength="20000">{{ $v('body') }}</textarea>@error('body')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>يُنشر ابتداءً من (بتوقيت {{ $zone }})<input class="form-control" type="datetime-local" name="publish_at" value="{{ $local('publish_at') }}">@error('publish_at')<span class="field-error">{{ $message }}</span>@enderror<small class="text-muted fw-normal">لا يظهر للزوار قبل هذا الوقت حتى بعد اعتماده.</small></label>
<label>ينتهي عرضه في<input class="form-control" type="datetime-local" name="expires_at" value="{{ $local('expires_at') }}">@error('expires_at')<span class="field-error">{{ $message }}</span>@enderror</label>
@if($record->event)<p class="small mb-0" data-linked-event>مرتبط بالمناسبة: <strong>{{ $record->event->title }}</strong></p>@endif
<fieldset class="content-governance" data-death-fields>
    <legend class="h6">بيانات النعي والعزاء (للتصنيفين «نعي ووفاة» و«عزاء»)</legend>
    <p class="small text-muted mb-0">كل الحقول اختيارية وتُنشر وفق موافقة ذوي المتوفى. ربط النعي بسجل في الشجرة لا يغيّر حالة الشخص؛ تغيير حالة الوفاة يتم بطلب تغيير يراجعه مدير العائلة.</p>
    <label>اسم المتوفى<input class="form-control" name="deceased_name" value="{{ $v('deceased_name') }}" maxlength="255"></label>
    @php($deceased = old('deceased_person_id') ? \App\Models\Person::query()->find(old('deceased_person_id')) : $record->deceasedPerson)
    <label>السجل في الشجرة (اختياري)<select class="form-select" name="deceased_person_id" data-remote-select data-remote-url="{{ route('management.lookups.people', [$family->slug, 'tree' => $tree->slug]) }}"><option value="">دون ربط</option>@if($deceased)<option value="{{ $deceased->id }}" selected>{{ $deceased->display_name }}</option>@endif</select>@error('deceased_person_id')<span class="field-error">{{ $message }}</span>@enderror</label>
    <label>تاريخ الوفاة<input class="form-control" type="date" name="death_date" value="{{ old('death_date', $record->death_date?->format('Y-m-d')) }}">@error('death_date')<span class="field-error">{{ $message }}</span>@enderror</label>
    <label>صلاة الجنازة (الوقت والمكان)<textarea class="form-control" name="funeral_prayer" rows="2">{{ $v('funeral_prayer') }}</textarea></label>
    <label>الدفن والمقبرة<textarea class="form-control" name="burial_place" rows="2">{{ $v('burial_place') }}</textarea></label>
    <label>مكان استقبال العزاء<textarea class="form-control" name="condolence_place" rows="2">{{ $v('condolence_place') }}</textarea></label>
    <label>مواعيد العزاء<textarea class="form-control" name="condolence_times" rows="2">{{ $v('condolence_times') }}</textarea></label>
    <label class="flex-row align-items-center gap-2"><input type="checkbox" name="family_consent" value="1" @checked($v('family_consent'))> وافق ذوو المتوفى على نشر هذه البيانات</label>
    @error('family_consent')<span class="field-error">{{ $message }}</span>@enderror
    <label>ملاحظة التحقق (لمن تحقق ومتى)<textarea class="form-control" name="sensitive_note" rows="2" maxlength="2000">{{ $v('sensitive_note') }}</textarea></label>
    @if($record->sensitive_confirmed_at)<p class="small mb-0" data-sensitive-confirmed>أكّد التحقق {{ $record->sensitiveConfirmer?->name }} في {{ $record->sensitive_confirmed_at->setTimezone($zone)->format('Y-m-d H:i') }}.</p>@endif
</fieldset>
<fieldset class="content-governance"><legend class="h6">المكان (للعزاء أو اللقاء)</legend>@include('management.entities.content.fields.place')</fieldset>
