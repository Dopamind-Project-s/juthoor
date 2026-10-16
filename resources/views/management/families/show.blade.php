@extends('layouts.management')
@section('breadcrumb','العائلات / '.$family->name_ar)
@section('page-heading','إدارة '.$family->name_ar)
@section('title','إدارة '.$family->name_ar)
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4"><div><span class="status-badge">{{ ['reader'=>'قارئ','editor'=>'محرر','admin'=>'مدير','super_admin'=>'سوبر أدمن'][$role] }}</span><h1 class="h3 mt-2">لوحة العائلة</h1></div><a class="btn btn-primary" href="{{ route('genealogy.tree',[$family->slug, 'tree' => $tree->slug, 'private' => 1]) }}">معاينة الشجرة</a></div>
@if(!$section || $section === "trees")
<section class="management-panel" aria-label="الشجرة المختارة">
<form method="get" class="management-grid"><label for="managementTree">الشجرة الحالية</label><select id="managementTree" class="form-select" name="tree" onchange="this.form.submit()">@foreach($trees as $item)<option value="{{ $item->slug }}" @selected($tree->is($item))>{{ $item->name_ar }}{{ $item->is_primary ? ' — الأساسية' : '' }}{{ !$item->is_active ? ' — معطلة' : '' }}</option>@endforeach</select><button class="btn btn-outline-primary">اختيار</button></form>
@if($isAdmin)<form method="post" action="{{ route('management.trees.update', [$family->slug, $tree->id, 'tree' => $tree->slug]) }}" class="management-grid mt-3">@csrf @method('PUT')
@include('management.trees.root-fields',['rootTree'=>$tree])
<button class="btn btn-primary">حفظ الجذر</button></form>@endif
@if(!$tree->root_person_id)
@can('update',$family)<a class="btn btn-primary mt-3" href="{{ route('management.records.create',[$family->slug,'people','tree'=>$tree->slug,'make_root'=>1]) }}">إضافة جذر الشجرة الآن</a>@endcan
<p class="alert alert-info mt-3">ابدأ بإضافة الجذر كشخص، ثم اختره هنا. للنشر العام، اختر حالة تاريخي وغير حي وتوثيق موثق أو مسند للأشخاص والعلاقات والفروع.</p>@endif
</section>
@endif
@if(!$section)
<div class="dashboard-stats"><div class="dashboard-stat"><small>الأشخاص</small><strong>{{ $statistics['people'] }}</strong></div><div class="dashboard-stat"><small>العلاقات</small><strong>{{ $statistics['relationships'] }}</strong></div><div class="dashboard-stat"><small>الفروع</small><strong>{{ $statistics['branches'] }}</strong></div><div class="dashboard-stat"><small>قيد المراجعة</small><strong>{{ $statistics['pending'] }}</strong></div></div>
@endif

@if($section)
<div class="d-flex flex-wrap justify-content-between gap-3 mb-3"><h2 class="h4">{{ ['people'=>'الأشخاص','relationships'=>'العلاقات','branches'=>'الفروع','trees'=>'الأشجار','members'=>'الأعضاء','invitations'=>'الدعوات','audit'=>'سجل التغييرات'][$section] }}</h2>
@if($section !== 'audit' && (in_array($section,['trees','members','invitations']) ? $isAdmin : $canEdit))
<a class="btn btn-primary" href="{{ route('management.records.create',[$family->slug,$section,'tree'=>$tree->slug]) }}">إضافة سجل</a>@endif</div>
@if(!in_array($section,['people','trees']))<form method="get" class="management-panel management-grid"><input type="hidden" name="tree" value="{{ $tree->slug }}"><label>بحث<input class="form-control" type="search" name="q" value="{{ request('q') }}" placeholder="بحث في السجلات"></label>@if(in_array($section,['members','invitations']))<label>الدور<select class="form-select" name="role"><option value="">كل الأدوار</option>@foreach(['reader'=>'قارئ','editor'=>'محرر','admin'=>'مدير'] as $value=>$label)<option value="{{ $value }}" @selected(request('role')===$value)>{{ $label }}</option>@endforeach</select></label>@endif<button class="btn btn-primary">بحث وتصفية</button></form>@endif
@endif
@if(!$section)
<div class="management-cards">
@foreach(['people'=>'الأشخاص','relationships'=>'العلاقات','branches'=>'الفروع','trees'=>'الأشجار','members'=>'الأعضاء','invitations'=>'الدعوات','audit'=>'سجل التغييرات'] as $key=>$label)
@if(!in_array($key,['members','invitations']) || $isAdmin)
<a class="management-panel" href="{{ route('management.sections.index',[$family->slug,$key,'tree'=>$tree->slug]) }}"><h2 class="h5">{{ $label }}</h2><span>عرض وإدارة السجلات ←</span></a>
@endif
@endforeach
</div>
@endif
@foreach(['people','relationships','branches','members','invitations','audit'] as $panel)
@if(!$section || $section===$panel)
@include('management.sections.'.$panel)
@endif
@endforeach
@if($section==='trees')
<section class="management-panel management-list">
@foreach($treeRecords as $item)<article><div><strong>{{ $item->name_ar }}</strong><small>{{ $item->is_primary ? 'أساسية' : 'إضافية' }} · {{ $item->is_active ? 'نشطة' : 'معطلة' }}</small></div><a class="btn btn-outline-primary" href="{{ route('management.records.show',[$family->slug,'trees',$item->id,'tree'=>$item->slug]) }}">تفاصيل</a>@if($isAdmin)<a class="btn btn-primary" href="{{ route('management.records.edit',[$family->slug,'trees',$item->id,'tree'=>$item->slug]) }}">تعديل</a>@endif</article>@endforeach
{{ $treeRecords->links() }}</section>
@endif
@endsection
