@php
$fatherEdge = $person?->outgoingRelationships()->where('relationship_type','father')->where('is_primary',true)->first();
$fatherId = old('father_id', $fatherEdge?->related_person_id ?? (($prefillFather ?? null)?->id));
$selectedFather = $fatherId ? $tree->people()->find($fatherId) : null;
$isRootPerson = $person && $tree->root_person_id === $person->id;
$founded = $person ? $person->foundedBranches()->where('family_tree_id',$tree->id)->get() : collect();
$editFounded = old('founded_branch_id') ? $founded->firstWhere('id',old('founded_branch_id')) : null;
@endphp
<fieldset class="connected-fields" data-founder-fields><legend class="h5">الفرع / العائلة الفرعية</legend>
@if($founded->isNotEmpty())
<p>الفروع التي يؤسسها هذا الشخص:</p>
@foreach($founded as $foundedBranch)<a class="btn btn-sm btn-outline-primary" href="{{ route('management.records.edit',[$family->slug,'branches',$foundedBranch->id,'tree'=>$tree->slug]) }}">{{ $foundedBranch->name_ar }} — تعديل وإدارة</a>@endforeach
@endif
<label><input type="hidden" name="branch_enabled" value="0"><input type="checkbox" name="branch_enabled" value="1" data-founder-toggle @checked(old('branch_enabled',false))> هل هذا الشخص مؤسس فرع / عائلة فرعية؟ إضافة فرع أو تحديثه</label>
<p class="text-muted">الفرع جزء من نفس العائلة والشجرة. عضويته تُحسب من ذرية المؤسس. إلغاء الاختيار لا يحذف أي فرع قائم؛ الحذف إجراء مستقل من صفحة الفرع.</p>
<div data-founder-inputs class="management-grid">
@if($founded->isNotEmpty())<label>الفرع المراد حفظه<select name="founded_branch_id" class="form-select" data-founded-branch>
<option value="">إنشاء فرع جديد</option>
@foreach($founded as $foundedBranch)<option value="{{ $foundedBranch->id }}" @selected(old('founded_branch_id')==$foundedBranch->id) data-branch='{{ json_encode(["name_ar"=>$foundedBranch->name_ar,"slug"=>$foundedBranch->slug,"description"=>$foundedBranch->description,"parent_id"=>$foundedBranch->parent_branch_id,"parent_name"=>$foundedBranch->parentBranch?->name_ar,"is_active"=>$foundedBranch->is_active,"verification"=>$foundedBranch->verification_status]) }}'>{{ $foundedBranch->name_ar }}</option>@endforeach
</select></label>@endif
<label>اسم الفرع<input name="branch_name_ar" class="form-control" value="{{ old('branch_name_ar',$editFounded?->name_ar) }}" placeholder="مثال: فرع مصعب"></label>
<label>نوع الكيان<select name="branch_type" class="form-select">@foreach(\App\Models\Branch::TYPES as $value=>$label)<option value="{{ $value }}" @selected(old('branch_type',$editFounded?->branch_type ?? \App\Models\Branch::TYPE_FAMILY_GROUP)===$value)>{{ $label }}</option>@endforeach</select></label>
<label>الرابط المختصر<input name="branch_slug" class="form-control" value="{{ old('branch_slug',$editFounded?->slug) }}" placeholder="musab-branch"></label>
@php($parentBranch = old('branch_parent_id') ? $tree->branches()->find(old('branch_parent_id')) : $editFounded?->parentBranch)
<label>الفرع الأب (اختياري)<select name="branch_parent_id" class="form-select" data-remote-select data-remote-url="{{ route('management.lookups.branches',[$family->slug,'tree'=>$tree->slug]) }}"><option value="">فرع مستقل داخل الشجرة</option>@if($parentBranch)<option value="{{ $parentBranch->id }}" selected>{{ $parentBranch->name_ar }}</option>@endif</select></label>
<label>وصف الفرع<textarea name="branch_description" class="form-control" placeholder="وصف مختصر">{{ old('branch_description',$editFounded?->description) }}</textarea></label>
<label>توثيق الفرع<select name="branch_verification_status" class="form-select">@include('management.partials.verification-options',['selected'=>old('branch_verification_status',$editFounded?->verification_status ?? 'pending_review')])</select></label>
<label><input type="checkbox" name="branch_is_active" value="1" @checked(old('branch_is_active',$editFounded?->is_active ?? true))> فرع نشط</label>
</div>
</fieldset>
