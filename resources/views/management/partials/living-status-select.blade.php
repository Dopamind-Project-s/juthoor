@php($livingValue = $selected === null || $selected === '' ? '' : ((string) (int) filter_var($selected, FILTER_VALIDATE_BOOLEAN)))
<label>حالة الحياة<select class="form-select" name="{{ $name }}">
<option value="" @selected($livingValue === '')>غير معروفة (لا تُنشر)</option>
<option value="0" @selected($livingValue === '0')>متوفى</option>
<option value="1" @selected($livingValue === '1')>على قيد الحياة</option>
</select><small class="text-muted">الحالة غير المعروفة لا تعني الوفاة، ولا يظهر صاحبها للعامة.</small>@error($name)<span class="field-error">{{ $message }}</span>@enderror</label>
