@extends('layouts.management')
@section('title', $title)
@section('page-heading', $family->name_ar.' / '.$title)
@section('content')
<div class="d-flex flex-wrap justify-content-between gap-3 mb-4"><h1 class="h3">{{ ['create'=>'إضافة','edit'=>'تعديل','show'=>'تفاصيل'][$mode] }} — {{ $title }}</h1><a class="btn btn-outline-primary" href="{{ route('management.sections.index',[$family->slug,$section,'tree'=>$tree->slug]) }}">العودة للقائمة</a></div>
<section class="management-panel">
@if($mode==='show')
@if($section==='people')<h2 class="h4">{{ $record->display_name }}</h2><x-person-descriptors :person="$record" />@endif
<dl class="row">
@foreach(['id'=>'الرقم','full_name_ar'=>'الاسم الكامل','name_ar'=>'الاسم','canonical_key'=>'المفتاح المرجعي','slug'=>'الرابط','description'=>'الوصف','notes'=>'ملاحظات','status'=>'الحالة','verification_status'=>'التوثيق','email'=>'البريد الإلكتروني','role'=>'الدور','is_active'=>'نشط','is_primary'=>'أساسي','created_at'=>'تاريخ الإنشاء','expires_at'=>'انتهاء الدعوة','accepted_at'=>'قبول الدعوة','cancelled_at'=>'إلغاء الدعوة'] as $key=>$label)
@if($record->$key!==null)<dt class="col-sm-4">{{ $label }}</dt><dd class="col-sm-8">{{ is_bool($record->$key) ? ($record->$key ? 'نعم' : 'لا') : $record->$key }}</dd>@endif
@endforeach
@if($section==='relationships')<dt class="col-sm-4">الابن</dt><dd class="col-sm-8">{{ $record->person->display_name }}</dd><dt class="col-sm-4">الأب</dt><dd class="col-sm-8">{{ $record->relatedPerson->display_name }}</dd>@endif
@if($section==='members')<dt class="col-sm-4">العضو</dt><dd class="col-sm-8">{{ $record->user->name }} · {{ $record->user->email }}</dd>@endif
@if(in_array($section,['trees','branches']))<dt class="col-sm-4">الجذر / المؤسس</dt><dd class="col-sm-8">{{ $record->rootPerson?->display_name ?? 'غير محدد' }}</dd>@endif
</dl>
@if($canEdit)<a class="btn btn-primary" href="{{ route('management.records.edit',[$family->slug,$section,$record->id,'tree'=>$tree->slug]) }}">تعديل وإدارة</a>@endif
@if($section==='people' && $canEdit)<a class="btn btn-primary" href="{{ route('management.records.create',[$family->slug,'people','tree'=>$tree->slug,'father_id'=>$record->id]) }}">إضافة ابن / ابنة</a>@endif
@if($section==='people')<a class="btn btn-outline-primary" href="{{ route('management.people.show',[$family->slug,$record->id,'tree'=>$tree->slug]) }}">العلاقات والمصادر</a>@endif
@else
@php
$routeSection = $section==='members' ? 'memberships' : $section;
$action = match($section) {
'trees' => $record ? route('management.trees.details',[$family->slug,$record->id]) : route('management.trees.store',$family->slug),
'invitations' => $record ? route('invitations.update',[$family->slug,$record->id]) : route('invitations.store',$family->slug),
default => $record ? route('management.'.$routeSection.'.update',[$family->slug,$record->id,'tree'=>$tree->slug]) : route('management.'.$routeSection.'.store',[$family->slug,'tree'=>$tree->slug]),
};
@endphp
@if($section==='invitations' && $record && ($record->accepted_at || $record->cancelled_at))
<p class="alert alert-info">لا يمكن تعديل دعوة مقبولة أو ملغاة.</p>
@else
<form method="post" action="{{ $action }}" class="management-grid">@csrf @if($record) @method('PUT') @endif
@if($section==='people')@include('management.families.partials.person-fields',['person'=>$record])@endif
@if($section==='branches')@include('management.families.partials.branch-fields',['branch'=>$record])@endif
@if($section==='relationships')
@php
$selectedChild = old('person_id') ? $tree->people()->find(old('person_id')) : $record?->person;
$selectedFather = old('father_id') ? $tree->people()->find(old('father_id')) : $record?->relatedPerson;
@endphp
<label>الابن<select name="person_id" class="form-select" data-remote-select data-remote-url="{{ route('management.lookups.people',[$family->slug,'tree'=>$tree->slug]) }}" required><option value="">اختر الابن</option>@if($selectedChild)<option selected value="{{ $selectedChild->id }}">{{ $selectedChild->display_name }}</option>@endif</select></label>
<label>الأب<select name="father_id" class="form-select" data-remote-select data-remote-url="{{ route('management.lookups.people',[$family->slug,'tree'=>$tree->slug]) }}" required><option value="">اختر الأب</option>@if($selectedFather)<option selected value="{{ $selectedFather->id }}">{{ $selectedFather->display_name }}</option>@endif</select></label>
<label>حالة التوثيق<select name="verification_status" class="form-select">@include('management.partials.verification-options',['selected'=>old('verification_status',$record?->verification_status ?? 'pending_review'),'statuses'=>['pending_review','sourced','verified']])</select></label>
@endif
@if($section==='trees')
@include('management.trees.root-fields',['rootTree'=>$record])
<label>اسم الشجرة<input class="form-control" name="name_ar" value="{{ old('name_ar',$record?->name_ar) }}" placeholder="اسم الشجرة بالعربية" required></label>
@if(!$record)<label>الرابط المختصر<input class="form-control" name="slug" value="{{ old('slug') }}" placeholder="main" required></label>@endif
<label><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary',$record?->is_primary))> الشجرة الأساسية</label>
@if($record)<label><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$record->is_active))> شجرة نشطة</label>@endif
@endif
@if(in_array($section,['members','invitations']))
@if(!$record)<label>البريد الإلكتروني<input type="email" class="form-control" name="email" value="{{ old('email') }}" placeholder="name@example.com" required></label>@if($section==='members' && !auth()->user()->isSuperAdmin())<p class="text-muted small mb-0">تُرسل دعوة إلى هذا البريد، وتبدأ العضوية بعد قبول صاحبه لها.</p>@endif @else<p>{{ $record->email ?? $record->user->email }}</p>@endif
<label>الدور<select class="form-select" name="role">@foreach(['reader'=>'قارئ','editor'=>'محرر','admin'=>'مدير'] as $value=>$label)<option value="{{ $value }}" @selected(old('role',$record?->role)===$value)>{{ $label }}</option>@endforeach</select></label>
@endif
<div><button class="btn btn-primary">حفظ البيانات</button></div>
</form>
@endif
@if($record && in_array($section,['people','relationships','branches','members']))
<form class="mt-4" method="post" action="{{ route('management.'.$routeSection.'.destroy',[$family->slug,$record->id,'tree'=>$tree->slug]) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">حذف السجل</button></form>
@endif
@if($record && $section==='invitations' && !$record->accepted_at && !$record->cancelled_at)
<div class="d-flex gap-2 mt-4"><form method="post" action="{{ route('invitations.resend',[$family->slug,$record->id]) }}">@csrf<button class="btn btn-outline-primary">إعادة إرسال</button></form><form method="post" action="{{ route('invitations.cancel',[$family->slug,$record->id]) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">إلغاء الدعوة</button></form></div>
@endif
@endif
</section>
@endsection
