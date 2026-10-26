@extends('layouts.management')
@section('page-heading','لوحة العائلة')
@section('title','لوحة '.$family->name_ar)
@section('content')
@php
    $roleLabels = \App\Services\Dashboards\FamilyDashboardService::roleLabels();
    $healthLinks = [
        'without_father' => route('management.sections.index',[$family->slug,'people','tree'=>$tree->slug,'issue'=>'without_father']),
        'without_sources' => route('management.sections.index',[$family->slug,'people','tree'=>$tree->slug,'issue'=>'without_sources']),
        'possible_duplicates' => route('management.sections.index',[$family->slug,'people','tree'=>$tree->slug,'issue'=>'possible_duplicates']),
        'living_unknown' => route('management.sections.index',[$family->slug,'people','tree'=>$tree->slug,'issue'=>'living_unknown']),
        'living_legacy' => route('management.sections.index',[$family->slug,'people','tree'=>$tree->slug,'issue'=>'living_legacy']),
        'unresolved' => route('management.sections.index',[$family->slug,'people','tree'=>$tree->slug,'issue'=>'unresolved']),
        'pending_relationships' => route('management.sections.index',[$family->slug,'relationships','tree'=>$tree->slug]),
        'branches_without_founder' => route('management.sections.index',[$family->slug,'branches','tree'=>$tree->slug]),
    ];
    $levelIcons = ['ok' => 'bi-check-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'danger' => 'bi-x-octagon-fill'];
    $actionLabels = ['created' => 'إضافة', 'updated' => 'تحديث', 'deleted' => 'حذف'];
@endphp
<div class="family-dashboard-head">
    <div>
        <span class="status-badge">{{ $roleLabels[$role] ?? $role }}</span>
        @unless($family->is_active)<span class="badge text-bg-warning">العائلة معطلة</span>@endunless
        <h1 class="h3 mt-2 mb-1">{{ $family->name_ar }}</h1>
        <p class="text-muted mb-0">لوحة خاصة بهذه العائلة فقط؛ كل الأرقام أدناه محسوبة لشجرة «{{ $tree->name_ar }}».</p>
    </div>
    <form method="get" class="family-dashboard-tree" aria-label="اختيار الشجرة">
        <label for="dashboardTree" class="form-label small mb-1">الشجرة النشطة</label>
        <div class="d-flex gap-2"><select id="dashboardTree" name="tree" class="form-select" onchange="this.form.submit()">@foreach($trees as $item)<option value="{{ $item->slug }}" @selected($tree->is($item))>{{ $item->name_ar }}{{ $item->is_primary ? ' — الأساسية' : '' }}{{ ! $item->is_active ? ' — معطلة' : '' }}</option>@endforeach</select><noscript><button class="btn btn-outline-primary">عرض</button></noscript></div>
    </form>
</div>

<section class="quick-actions" aria-label="إجراءات سريعة">
    <a class="btn btn-outline-primary" href="{{ route('genealogy.tree',[$family->slug,'tree'=>$tree->slug,'private'=>1]) }}"><i class="bi bi-diagram-3"></i> معاينة الشجرة</a>
    @if($canEdit)
        <a class="btn btn-primary" href="{{ route('management.records.create',[$family->slug,'people','tree'=>$tree->slug]) }}"><i class="bi bi-person-plus"></i> إضافة شخص</a>
        <a class="btn btn-outline-primary" href="{{ route('management.records.create',[$family->slug,'relationships','tree'=>$tree->slug]) }}"><i class="bi bi-link-45deg"></i> إضافة علاقة</a>
        <a class="btn btn-outline-primary" href="{{ route('management.records.create',[$family->slug,'branches','tree'=>$tree->slug]) }}"><i class="bi bi-bezier2"></i> إضافة فرع</a>
        <a class="btn btn-outline-primary" href="{{ route('management.sources.index',[$family->slug,'tree'=>$tree->slug,'new'=>1]) }}#new-source"><i class="bi bi-journal-plus"></i> إضافة مصدر</a>
        <a class="btn btn-outline-primary" href="{{ route('management.imports.index',[$family->slug,'tree'=>$tree->slug]) }}"><i class="bi bi-upload"></i> استيراد CSV</a>
    @else
        <a class="btn btn-primary" href="{{ route('management.changes.create',[$family->slug,'tree'=>$tree->slug]) }}"><i class="bi bi-pencil-square"></i> اقتراح تغيير</a>
    @endif
    @if($isAdmin)
        <a class="btn btn-outline-primary" href="{{ route('management.sections.index',[$family->slug,'invitations','tree'=>$tree->slug]) }}"><i class="bi bi-envelope-plus"></i> دعوة عضو</a>
    @endif
</section>

<div class="dashboard-stats" aria-label="إحصاءات الشجرة">
@foreach(['people'=>'الأشخاص','relationships'=>'العلاقات','branches'=>'الفروع','pending_people'=>'أشخاص قيد المراجعة','pending_changes'=>'مقترحات معلقة','sources'=>'مصادر العائلة','citations'=>'الاستشهادات','trees'=>'أشجار العائلة'] as $key=>$label)
<div class="dashboard-stat"><small>{{ $label }}</small><strong>{{ number_format($statistics[$key]) }}</strong></div>
@endforeach
</div>

<div class="dashboard-columns">
<section class="management-panel" aria-labelledby="healthHeading">
    <span class="eyebrow">جودة البيانات</span><h2 class="h4" id="healthHeading">مؤشرات صحة الشجرة</h2>
    <ul class="health-list">
    @foreach($health as $indicator)
        <li class="health-{{ $indicator['level'] }}"><i class="bi {{ $levelIcons[$indicator['level']] }}" aria-hidden="true"></i><span class="visually-hidden">{{ ['ok'=>'سليم','warning'=>'تنبيه','danger'=>'خطر'][$indicator['level']] }}:</span>
            @if(isset($healthLinks[$indicator['key']]) && $indicator['count'])<a href="{{ $healthLinks[$indicator['key']] }}">{{ $indicator['label'] }}</a>@else<span>{{ $indicator['label'] }}</span>@endif
            <strong>{{ number_format($indicator['count']) }}</strong></li>
    @endforeach
    </ul>
    @if($duplicateGroups)
        <h3 class="h6 mt-3">أبرز التكرارات المحتملة</h3>
        <ul class="small mb-0">@foreach($duplicateGroups as $group)<li>{{ $group['name'] }} @if($group['father'])بن {{ $group['father'] }}@else— دون أب @endif · {{ $group['count'] }} سجلات</li>@endforeach</ul>
        <p class="small text-muted mt-2 mb-0">التكرار المحتمل لا يعني الخطأ؛ قد يحمل أخوان الاسم نفسه. لا يوجد دمج تلقائي.</p>
    @endif
</section>

<section class="management-panel" aria-labelledby="publicationHeading">
    <span class="eyebrow">الخصوصية والنشر</span><h2 class="h4" id="publicationHeading">حالة النشر</h2>
    <dl class="dashboard-facts">
        <dt>العائلة</dt><dd>{{ $publication['family_active'] ? 'نشطة' : 'معطلة — لا تظهر للعامة' }}</dd>
        <dt>الشجرة</dt><dd>{{ $publication['tree_active'] ? 'نشطة' : 'معطلة — معاينة خاصة فقط' }}</dd>
        <dt>الجذر</dt><dd>@if($publication['has_root']){{ $tree->rootPerson?->display_name }}@else<span class="text-danger">لم يُحدد بعد</span>@endif</dd>
        <dt>أشخاص ظاهرون للعامة</dt><dd>{{ number_format($publication['public_people']) }} من {{ number_format($statistics['people']) }}</dd>
        <dt>أحياء محميون</dt><dd>{{ number_format($publication['living_people']) }} (لا يظهرون في العرض العام)</dd>
    </dl>
    <p class="small text-muted mb-0">يظهر للعامة فقط الأشخاص التاريخيون المسجلة وفاتهم صراحةً ذوو التوثيق المعتمد. حالة الحياة غير المعروفة لا تعني الوفاة ولا تُنشر؛ راجعها من مؤشر «حالة الحياة غير معروفة». معلومات الميلاد والوفاة والملاحظات تخضع لإعداد خصوصية كل حقل.</p>
</section>
</div>

<div class="dashboard-columns">
<section class="management-panel" aria-labelledby="pendingHeading">
    <span class="eyebrow">بانتظار القرار</span><h2 class="h4" id="pendingHeading">الأعمال المعلقة</h2>
    <div class="management-list">
    @forelse($pendingChanges as $change)
        <article><div><strong>{{ ['person'=>'شخص','relationship'=>'علاقة أبوة','branch'=>'فرع'][$change->target_type] ?? $change->target_type }} — {{ ['create'=>'إضافة','update'=>'تعديل'][$change->operation] ?? $change->operation }}</strong><small>{{ \Illuminate\Support\Str::limit($change->reason, 80) }} · {{ $change->created_at?->format('Y-m-d') }}</small></div><a class="btn btn-sm btn-outline-primary" href="{{ route('management.changes.show',[$family->slug,$change->id,'tree'=>$tree->slug]) }}">مراجعة</a></article>
    @empty
        <p class="text-muted mb-0" role="status">لا توجد مقترحات معلقة في هذه الشجرة.</p>
    @endforelse
    </div>
    <ul class="dashboard-inline-facts">
        <li>دفعات CSV بانتظار الاعتماد: <strong>{{ $pendingImports }}</strong></li>
        @if($pendingNameDifferences > 0 && $canEdit)<li><a href="{{ route('management.reconciliation.index',[$family->slug,'tree'=>$tree->slug]) }}">اختلافات أسماء تاريخية لم يُبت فيها</a>: <strong>{{ $pendingNameDifferences }}</strong></li>@endif
        @if($isAdmin)<li>دعوات معلقة: <strong>{{ $pendingInvitations }}</strong></li><li>مطابقات أنساب مشتركة قيد المراجعة: <strong>{{ $pendingSharedAncestry }}</strong></li>@endif
    </ul>
</section>

@if($isAdmin)
<section class="management-panel" aria-labelledby="membersHeading">
    <span class="eyebrow">الصلاحيات</span><h2 class="h4" id="membersHeading">الأعضاء والأدوار</h2>
    <dl class="dashboard-facts">@foreach(['admin'=>'مدراء','editor'=>'محررون','reader'=>'قراء'] as $key=>$label)<dt>{{ $label }}</dt><dd>{{ $members[$key] ?? 0 }}</dd>@endforeach</dl>
    @if(($members['admin'] ?? 0) < 2)<p class="alert alert-warning small mb-0">يوجد مدير واحد أو أقل لهذه العائلة. أضف مديرًا ثانيًا لتجنب فقدان الإدارة.</p>@endif
    <a class="btn btn-sm btn-outline-primary mt-3" href="{{ route('management.sections.index',[$family->slug,'members','tree'=>$tree->slug]) }}">إدارة الأعضاء</a>
</section>
@endif
</div>

<section class="management-panel" aria-labelledby="branchesHeading">
    <div class="d-flex justify-content-between align-items-center"><div><span class="eyebrow">الفروع الرئيسية</span><h2 class="h4" id="branchesHeading">ملخص الفروع</h2></div><a class="btn btn-sm btn-outline-primary" href="{{ route('management.sections.index',[$family->slug,'branches','tree'=>$tree->slug]) }}">كل الفروع</a></div>
    <div class="management-list">
    @forelse($branches as $branch)
        <article><div><strong>{{ $branch->name_ar }}</strong><small>{{ $branch->type_label }} · المؤسس: {{ $branch->rootPerson?->display_name ?? 'غير محدد' }} · {{ $branch->child_branches_count }} فرع فرعي · {{ $branch->is_active ? 'نشط' : 'غير نشط' }}</small></div><a class="btn btn-sm btn-outline-primary" href="{{ route('management.records.show',[$family->slug,'branches',$branch->id,'tree'=>$tree->slug]) }}">تفاصيل</a></article>
    @empty
        <p class="text-muted mb-0" role="status">لا توجد فروع في هذه الشجرة بعد.</p>
    @endforelse
    </div>
</section>

<section class="management-panel" aria-labelledby="auditHeading">
    <div class="d-flex justify-content-between align-items-center"><div><span class="eyebrow">المساءلة</span><h2 class="h4" id="auditHeading">آخر التغييرات في العائلة</h2></div><a class="btn btn-sm btn-outline-primary" href="{{ route('management.sections.index',[$family->slug,'audit','tree'=>$tree->slug]) }}">السجل الكامل</a></div>
    <div class="management-list">
    @forelse($recentAudit as $log)
        <article><div><strong>{{ $actionLabels[$log->action] ?? $log->action }} · {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</strong><small>{{ $log->user?->name ?? 'النظام' }} · {{ $log->created_at?->format('Y-m-d H:i') }}</small></div><a class="btn btn-sm btn-outline-primary" href="{{ route('management.audit.show',$log->id) }}">تفاصيل</a></article>
    @empty
        <p class="text-muted mb-0" role="status">لم تُسجل تغييرات بعد.</p>
    @endforelse
    </div>
</section>
@endsection
