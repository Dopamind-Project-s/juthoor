@php($rootTree = $rootTree ?? null)
<fieldset class="connected-fields" data-root-fields>
<legend class="h5">جذر الشجرة</legend>
<p class="text-muted">للشجرة جذر واحد محدد صراحةً. الأشخاص غير المرتبطين بأب لا يصبحون جذورًا تلقائيًا.</p>
<label>طريقة تعيين الجذر<select name="root_mode" class="form-select" data-root-mode>
<option value="empty" @selected(old('root_mode', $rootTree?->root_person_id ? 'existing' : 'empty')==='empty')>شجرة فارغة / تعيين الجذر لاحقًا</option>
@if($rootTree)<option value="existing" @selected(old('root_mode', $rootTree->root_person_id ? 'existing' : 'empty')==='existing')>اختيار شخص من هذه الشجرة</option>@endif
<option value="new" @selected(old('root_mode')==='new')>إنشاء شخص جديد ليكون الجذر</option>
</select></label>
@if($rootTree)
<div data-root-existing>
@php($selectedRoot = old('root_person_id') ? $rootTree->people()->find(old('root_person_id')) : $rootTree->rootPerson)
<label>شخص الجذر<select name="root_person_id" class="form-select" data-remote-select data-remote-url="{{ route('management.lookups.people',[$rootTree->family->slug,'tree'=>$rootTree->slug]) }}"><option value="">اختر شخصًا دون أب</option>@if($selectedRoot)<option selected value="{{ $selectedRoot->id }}">{{ $selectedRoot->display_name }}</option>@endif</select></label>
@error('root_person_id')<p class="field-error">{{ $message }}</p>@enderror
</div>
@endif
<div data-root-new>
<label>الاسم الكامل للجذر<input name="root_name_ar" class="form-control" value="{{ old('root_name_ar') }}" placeholder="مثال: آدم" maxlength="255"></label>
<label>توثيق شخص الجذر<select name="root_verification_status" class="form-select">@foreach(['pending_review'=>'قيد المراجعة','sourced'=>'مسند','verified'=>'موثق'] as $value=>$label)<option value="{{ $value }}" @selected(old('root_verification_status','pending_review')===$value)>{{ $label }}</option>@endforeach</select></label>
@include('management.partials.living-status-select',['name'=>'root_is_living','selected'=>old('root_is_living')])
@error('root_name_ar')<p class="field-error">{{ $message }}</p>@enderror
</div>
@if($rootTree?->root_person_id)<p class="text-muted">تغيير الجذر لا ينقل علاقات الأبوة أو يحذفها. اختر شخصًا بلا أب، ثم عدّل العلاقات صراحةً من صفحة الشخص.</p>@endif
</fieldset>
