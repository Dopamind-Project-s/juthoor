<label>اللقب / الوصف المختصر (اختياري)<input name="title" class="form-control" value="{{ old('title',$person?->title) }}" placeholder="لقب منفصل عن الاسم" maxlength="255">@error('title')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>عبارة التكريم (اختياري)<input name="honorific" class="form-control" value="{{ old('honorific',$person?->honorific) }}" placeholder="نص للعرض فقط" maxlength="255"></label>
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
<label>الاسم الأول<input class="form-control" name="first_name_ar" placeholder="الاسم الأول" value="{{ old('first_name_ar',$person?->first_name_ar) }}" required>@error('first_name_ar')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>الاسم الكامل<input class="form-control" name="full_name_ar" placeholder="الاسم الكامل" value="{{ old('full_name_ar',$person?->full_name_ar) }}" required>@error('full_name_ar')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>المفتاح المرجعي<input class="form-control" name="canonical_key" placeholder="المفتاح المرجعي" value="{{ old('canonical_key',$person?->canonical_key) }}">@error('canonical_key')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>حالة الشخص<select class="form-select" name="status"><option value="historical" @selected(old('status',$person?->status ?? 'historical')==='historical')>تاريخي</option><option value="active" @selected(old('status',$person?->status)==='active')>نشط/خاص</option><option value="unresolved" @selected(old('status',$person?->status)==='unresolved')>غير محسوم</option></select>@error('status')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>حالة التوثيق<select class="form-select" name="verification_status"><option value="pending_review" @selected(old('verification_status',$person?->verification_status ?? 'pending_review')==='pending_review')>قيد المراجعة</option><option value="sourced" @selected(old('verification_status',$person?->verification_status)==='sourced')>مسند</option><option value="verified" @selected(old('verification_status',$person?->verification_status)==='verified')>موثق</option><option value="unresolved" @selected(old('verification_status',$person?->verification_status)==='unresolved')>غير محسوم</option></select>@error('verification_status')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>سنة الميلاد<input class="form-control" type="number" name="birth_year" placeholder="سنة الميلاد" value="{{ old('birth_year',$person?->birth_year) }}">@error('birth_year')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>سنة الوفاة<input class="form-control" type="number" name="death_year" placeholder="سنة الوفاة" value="{{ old('death_year',$person?->death_year) }}">@error('death_year')<span class="field-error">{{ $message }}</span>@enderror</label>
<label><input type="checkbox" name="is_living" value="1" @checked(old('is_living',$person?->is_living))> على قيد الحياة</label>
<label>الجنس<select class="form-select" name="gender"><option value="">غير محدد</option><option value="male" @selected(old('gender',$person?->gender)==='male')>ذكر</option><option value="female" @selected(old('gender',$person?->gender)==='female')>أنثى</option></select>@error('gender')<span class="field-error">{{ $message }}</span>@enderror</label>

@if(!request()->routeIs('management.changes.*'))
@include('management.families.partials.person-connections')
@endif
