@php
    $zone = old('timezone', $record->timezone ?: config('community.timezone'));
    $local = fn (string $field) => old($field, $record->{$field}?->setTimezone($zone)->format('Y-m-d\TH:i'));
@endphp
<label>التصنيف<select class="form-select" name="category">@foreach(\App\Models\CommunityEvent::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected($v('category', 'gathering') === $key)>{{ $label }}</option>@endforeach</select>@error('category')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>العنوان<input class="form-control" name="title" value="{{ $v('title') }}" required maxlength="255">@error('title')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>الوصف<textarea class="form-control" name="description" rows="5" maxlength="10000">{{ $v('description') }}</textarea></label>
<label>الجهة المنظمة<input class="form-control" name="organizer" value="{{ $v('organizer') }}" maxlength="255"></label>
<label>المنطقة الزمنية<input class="form-control" name="timezone" value="{{ $zone }}" dir="ltr" list="timezones" required>@error('timezone')<span class="field-error">{{ $message }}</span>@enderror<datalist id="timezones"><option value="Asia/Amman"><option value="Asia/Riyadh"><option value="Asia/Dubai"><option value="Europe/London"><option value="America/New_York"></datalist></label>
<label>البدء (بتوقيت المناسبة)<input class="form-control" type="datetime-local" name="starts_at" value="{{ $local('starts_at') }}" required>@error('starts_at')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>الانتهاء<input class="form-control" type="datetime-local" name="ends_at" value="{{ $local('ends_at') }}">@error('ends_at')<span class="field-error">{{ $message }}</span>@enderror</label>
<label class="flex-row align-items-center gap-2"><input type="checkbox" name="all_day" value="1" @checked($v('all_day'))> طوال اليوم</label>
@if($record->exists)<p class="small mb-0" data-event-status>الحالة: <strong>{{ \App\Models\CommunityEvent::STATUSES[$record->display_status] }}</strong> — تُغيّر من «تحديثات وتغييرات الموعد» أدناه.</p>@endif
<fieldset class="content-governance"><legend class="h6">المكان</legend>@include('management.entities.content.fields.place')</fieldset>

@push('scripts')<script src="{{ asset('js/community-publications.js') }}" defer></script>@endpush
<button class="btn btn-outline-primary" type="button" data-publication-preview>معاينة قبل الحفظ</button>
<section class="content-card" data-publication-preview-panel hidden aria-live="polite"><p class="small text-muted">معاينة محلية؛ لا تنشر المناسبة ولا ترسل إشعارات.</p><h2 data-preview-title></h2><p data-preview-summary></p><div class="publication-preview-body" data-preview-body></div></section>

@push('styles')<link rel="stylesheet" href="{{ asset('css/community-publications.css') }}">@endpush
