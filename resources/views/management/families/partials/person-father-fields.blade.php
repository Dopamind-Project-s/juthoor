@php
$fatherEdge = $person?->outgoingRelationships()->where('relationship_type','father')->where('is_primary',true)->first();
$fatherId = old('father_id', $fatherEdge?->related_person_id ?? (($prefillFather ?? null)?->id));
$selectedFather = $fatherId ? $tree->people()->find($fatherId) : null;
$isRootPerson = $person && $tree->root_person_id === $person->id;
$founded = $person ? $person->foundedBranches()->where('family_tree_id',$tree->id)->get() : collect();
$editFounded = old('founded_branch_id') ? $founded->firstWhere('id',old('founded_branch_id')) : null;
@endphp
<fieldset class="connected-fields"><legend class="h5">الأب وموقع الشخص في الشجرة</legend>
@if($isRootPerson)
<p class="alert alert-info">هذا الشخص هو جذر الشجرة. لا يمكن تعيين أب له. يجب على مدير العائلة تغيير الجذر أولًا من إعدادات الشجرة.</p>
@else
<label>الأب<select name="father_id" class="form-select" data-father-select data-remote-select data-remote-url="{{ route('management.lookups.people',[$family->slug,'tree'=>$tree->slug,'exclude'=>$person?->id]) }}"><option value="">الأب غير معروف / غير مرتبط بعد</option>@if($selectedFather)<option value="{{ $selectedFather->id }}" selected>{{ $selectedFather->display_name }}</option>@endif</select></label>
@error('father_id')<p class="field-error">{{ $message }}</p>@enderror
<label>توثيق علاقة الأبوة<select name="father_verification_status" class="form-select">@include('management.partials.verification-options',['selected'=>old('father_verification_status',$fatherEdge?->verification_status ?? 'pending_review')])</select></label>
<p class="text-muted">يُحدد الموضع والذرية تلقائيًا من علاقة الأبوة؛ لا حاجة لتحديد الجيل. عند اختيار «غير معروف» يبقى الشخص للمراجعة دون أن يصبح جذرًا آخر.</p>
@if($fatherEdge)<p class="text-muted">اختيار «غير مرتبط» يزيل علاقة الأبوة الحالية صراحةً بعد فحص حماية الفروع والاستشهادات.</p>@endif
@endif
@if(!$person && !$tree->root_person_id)
@can('update',$family)<label><input type="checkbox" name="make_root" value="1" @checked(old('make_root',request()->boolean('make_root')))> تعيين هذا الشخص جذرًا للشجرة (يجب أن يكون دون أب)</label>@endcan
@endif
</fieldset>
