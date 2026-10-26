@php($selectedPerson = old('person_id') ? \App\Models\Person::query()->find(old('person_id')) : $record->person)
<label>العنوان<input class="form-control" name="title" value="{{ $v('title') }}" required maxlength="255">@error('title')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>التصنيف<select class="form-select" name="category">@foreach(\App\Models\ArchiveRecord::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected($v('category', 'document') === $key)>{{ $label }}</option>@endforeach</select>@error('category')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>الوصف<textarea class="form-control" name="description" rows="5" maxlength="10000">{{ $v('description') }}</textarea></label>
<label>المصدر الأصلي (أين توجد المادة)<textarea class="form-control" name="original_source" rows="2" maxlength="2000">{{ $v('original_source') }}</textarea></label>
<label>الفترة التاريخية<input class="form-control" name="period_label" value="{{ $v('period_label') }}" maxlength="120"></label>
<label>من سنة<input class="form-control" type="number" name="start_year" value="{{ $v('start_year') }}">@error('start_year')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>إلى سنة<input class="form-control" type="number" name="end_year" value="{{ $v('end_year') }}">@error('end_year')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>المساهم<input class="form-control" name="contributor" value="{{ $v('contributor') }}" maxlength="255"></label>
<label>الشخص المرتبط في الشجرة (اختياري)<select class="form-select" name="person_id" data-remote-select data-remote-url="{{ route('management.lookups.people', [$family->slug, 'tree' => $tree->slug]) }}"><option value="">دون شخص</option>@if($selectedPerson)<option value="{{ $selectedPerson->id }}" selected>{{ $selectedPerson->display_name }}</option>@endif</select>@error('person_id')<span class="field-error">{{ $message }}</span>@enderror<small class="text-muted fw-normal">يُخفى السجل عن الزوار إذا كان الشخص غير منشور.</small></label>
@include('management.entities.content.fields.rights')
