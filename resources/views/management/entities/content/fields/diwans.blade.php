<label>الاسم<input class="form-control" name="name" value="{{ $v('name') }}" required maxlength="255">@error('name')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>النوع<select class="form-select" name="kind">@foreach(\App\Models\Diwan::KINDS as $key => $label)<option value="{{ $key }}" @selected($v('kind', 'diwan') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>الوصف والاستخدامات<textarea class="form-control" name="description" rows="3" maxlength="5000">{{ $v('description') }}</textarea></label>
<label>المحافظة<input class="form-control" name="governorate" value="{{ $v('governorate') }}" maxlength="120"></label>
<label>المدينة<input class="form-control" name="city" value="{{ $v('city') }}" maxlength="120"></label>
<label>العنوان العام<input class="form-control" name="public_address" value="{{ $v('public_address') }}" maxlength="255">@error('public_address')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>خط العرض (اختياري)<input class="form-control" name="latitude" value="{{ $v('latitude') }}" dir="ltr" inputmode="decimal">@error('latitude')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>خط الطول (اختياري)<input class="form-control" name="longitude" value="{{ $v('longitude') }}" dir="ltr" inputmode="decimal">@error('longitude')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>Google Place ID (اختياري)<input class="form-control" name="google_place_id" value="{{ $v('google_place_id') }}" dir="ltr">@error('google_place_id')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>رابط Google Maps (اختياري)<input class="form-control" name="maps_url" value="{{ $v('maps_url') }}" dir="ltr" placeholder="https://maps.app.goo.gl/…">@error('maps_url')<span class="field-error">{{ $message }}</span>@enderror<small class="text-muted fw-normal">أدخل الإحداثيات أو المعرف من الخريطة نفسها؛ لا تُقدّر.</small></label>
<label>جهة التواصل<input class="form-control" name="contact_name" value="{{ $v('contact_name') }}" maxlength="255"></label>
<label>رقم التواصل<input class="form-control" name="contact_phone" value="{{ $v('contact_phone') }}" dir="ltr">@error('contact_phone')<span class="field-error">{{ $message }}</span>@enderror</label>
<label class="flex-row align-items-center gap-2"><input type="checkbox" name="contact_consent" value="1" @checked($v('contact_consent'))> وافقت جهة التواصل على عرض بياناتها للعامة</label>
<label>تعليمات الزيارة<textarea class="form-control" name="visit_instructions" rows="2" maxlength="2000">{{ $v('visit_instructions') }}</textarea></label>
<label class="flex-row align-items-center gap-2"><input type="checkbox" name="is_private_residence" value="1" @checked($v('is_private_residence'))> المكان منزل خاص</label>
<label class="flex-row align-items-center gap-2"><input type="checkbox" name="residence_consent" value="1" @checked($v('residence_consent'))> وافق صاحب المنزل كتابيًا على نشره ديوانًا عامًا</label>
@error('residence_consent')<span class="field-error">{{ $message }}</span>@enderror
