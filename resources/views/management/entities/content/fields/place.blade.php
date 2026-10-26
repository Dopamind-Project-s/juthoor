<label>اسم المكان<input class="form-control" name="location_name" value="{{ $v('location_name') }}" maxlength="255"></label>
<label>العنوان العام<input class="form-control" name="public_address" value="{{ $v('public_address') }}" maxlength="255"><small class="text-muted fw-normal">مكان عام فقط؛ لا تُدخل عنوان منزل خاص دون موافقة أصحابه.</small></label>
<label>المدينة<input class="form-control" name="city" value="{{ $v('city') }}" maxlength="120"></label>
<label>المحافظة<input class="form-control" name="governorate" value="{{ $v('governorate') }}" maxlength="120"></label>
<label>خط العرض (اختياري)<input class="form-control" name="latitude" value="{{ $v('latitude') }}" dir="ltr" inputmode="decimal">@error('latitude')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>خط الطول (اختياري)<input class="form-control" name="longitude" value="{{ $v('longitude') }}" dir="ltr" inputmode="decimal">@error('longitude')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>رابط Google Maps (اختياري)<input class="form-control" name="maps_url" value="{{ $v('maps_url') }}" dir="ltr">@error('maps_url')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>جهة الاستفسار<input class="form-control" name="contact_name" value="{{ $v('contact_name') }}" maxlength="255"></label>
<label>رقم الاستفسار<input class="form-control" name="contact_phone" value="{{ $v('contact_phone') }}" dir="ltr">@error('contact_phone')<span class="field-error">{{ $message }}</span>@enderror</label>
<label class="flex-row align-items-center gap-2"><input type="checkbox" name="contact_consent" value="1" @checked($v('contact_consent'))> أذن صاحب الرقم بنشره</label>
