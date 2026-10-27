<label>حالة التوثيق<select class="form-select" name="verification_status">@include('management.partials.verification-options',['selected'=>old('verification_status',$person?->verification_status ?? 'pending_review')])</select>@error('verification_status')<span class="field-error">{{ $message }}</span>@enderror</label>
@if(!request()->routeIs('management.changes.*') && auth()->user()->can('managePrivacy',$family))
<fieldset class="connected-fields"><legend class="h5">خصوصية الحقول</legend><p class="text-muted">تحدد من يرى كل معلومة. لا تظهر أي معلومة للعامة إذا كان الشخص حيًا أو غير معروف الحالة.</p>
@foreach(\App\Services\Genealogy\PersonPrivacy::FIELDS as $field=>$default)<label>{{ \App\Services\Genealogy\PersonPrivacy::LABELS[$field] }}<select class="form-select" name="field_visibility[{{ $field }}]">@foreach(\App\Services\Genealogy\PersonPrivacy::LEVELS as $level=>$levelLabel)<option value="{{ $level }}" @selected(old('field_visibility.'.$field, $person?->field_visibility[$field] ?? $default)===$level)>{{ $levelLabel }}</option>@endforeach</select></label>@endforeach
</fieldset>
@endif
