<label>اسم المنطقة<input class="form-control" name="name" value="{{ $v('name') }}" required maxlength="255">@error('name')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>الدولة<input class="form-control" name="country" value="{{ $v('country') }}" maxlength="120"></label>
<label>المحافظة<input class="form-control" name="governorate" value="{{ $v('governorate') }}" maxlength="120"></label>
<label>المدينة أو البلدة<input class="form-control" name="city" value="{{ $v('city') }}" maxlength="120"></label>
<label>تاريخي أم حالي<select class="form-select" name="era">@foreach(\App\Models\CommunityLocation::ERAS as $key => $label)<option value="{{ $key }}" @selected($v('era', 'current') === $key)>{{ $label }}</option>@endforeach</select>@error('era')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>التصنيف<select class="form-select" name="kind">@foreach(\App\Models\CommunityLocation::KINDS as $key => $label)<option value="{{ $key }}" @selected($v('kind', 'gathering') === $key)>{{ $label }}</option>@endforeach</select>@error('kind')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>الفترة<input class="form-control" name="period_label" value="{{ $v('period_label') }}" maxlength="120"></label>
<label>الوصف<textarea class="form-control" name="description" rows="4" maxlength="5000">{{ $v('description') }}</textarea><small class="text-muted fw-normal">منطقة للجماعة، لا عنوان لشخص. لا تُدخل أرقام سكان أو مسارات هجرة دون مصدر.</small>@error('description')<span class="field-error">{{ $message }}</span>@enderror</label>
