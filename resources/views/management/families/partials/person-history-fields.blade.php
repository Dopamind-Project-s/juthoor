<label>المفتاح المرجعي<input class="form-control" name="canonical_key" placeholder="المفتاح المرجعي" value="{{ old('canonical_key',$person?->canonical_key) }}">@error('canonical_key')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>حالة الشخص<select class="form-select" name="status"><option value="historical" @selected(old('status',$person?->status ?? 'historical')==='historical')>تاريخي</option><option value="active" @selected(old('status',$person?->status)==='active')>نشط/خاص</option><option value="unresolved" @selected(old('status',$person?->status)==='unresolved')>غير محسوم</option></select>@error('status')<span class="field-error">{{ $message }}</span>@enderror</label>
@php
    $privacy = app(\App\Services\Genealogy\PersonPrivacy::class);
    $privacyAudience = $privacy->audience(auth()->user(), $family);
    $fieldVisible = fn (string $field) => $privacy->allows($privacyAudience, $privacy->level($field, old('field_visibility', $person?->field_visibility), old('is_living', $person?->is_living)));
    $restrictedNote = '<p class="text-muted mb-0"><i class="bi bi-lock"></i> %s: محجوب بحسب إعداد الخصوصية؛ تبقى قيمته دون تغيير.</p>';
@endphp
@if($fieldVisible('birth_year'))<label>سنة الميلاد<input class="form-control" type="number" name="birth_year" placeholder="سنة الميلاد" value="{{ old('birth_year',$person?->birth_year) }}">@error('birth_year')<span class="field-error">{{ $message }}</span>@enderror</label>@else{!! sprintf($restrictedNote, 'سنة الميلاد') !!}@endif
@if($fieldVisible('death_year'))<label>سنة الوفاة<input class="form-control" type="number" name="death_year" placeholder="سنة الوفاة" value="{{ old('death_year',$person?->death_year) }}">@error('death_year')<span class="field-error">{{ $message }}</span>@enderror</label>@else{!! sprintf($restrictedNote, 'سنة الوفاة') !!}@endif
@include('management.partials.living-status-select',['name'=>'is_living','selected'=>old('is_living',$person?->is_living)])
@if($fieldVisible('gender'))<label>الجنس<select class="form-select" name="gender"><option value="">غير محدد</option><option value="male" @selected(old('gender',$person?->gender)==='male')>ذكر</option><option value="female" @selected(old('gender',$person?->gender)==='female')>أنثى</option></select>@error('gender')<span class="field-error">{{ $message }}</span>@enderror</label>@else{!! sprintf($restrictedNote, 'الجنس') !!}@endif
@php
$currentAttributeIds = $person?->personAttributes()->pluck('person_attributes.id')->all() ?? [];
$attributeOptions = \App\Models\PersonAttribute::query()->availableFor($family->id)->where(fn($query) => $query->where('is_active',true)->orWhereIn('id',$currentAttributeIds))->orderBy('label')->get();
@endphp
<fieldset class="connected-fields"><legend class="h5">الصفات</legend><input type="hidden" name="attributes_present" value="1">
@forelse($attributeOptions as $attribute)
<label><input type="checkbox" name="attribute_ids[]" value="{{ $attribute->id }}" @checked(in_array($attribute->id,old('attribute_ids',old('attributes_present') ? [] : $currentAttributeIds)))> <span class="person-attribute" style="--attribute-color:{{ $attribute->color }};--attribute-ink:{{ $attribute->text_color }}">{{ $attribute->label }}</span>{{ !$attribute->is_active ? ' (معطلة — ارتباط قائم)' : '' }}</label>
@empty<p class="text-muted">لا توجد صفات متاحة. يمكن لمدير العائلة إضافتها من صفحة الصفات.</p>@endforelse
@error('attribute_ids')<span class="field-error">{{ $message }}</span>@enderror
</fieldset>
