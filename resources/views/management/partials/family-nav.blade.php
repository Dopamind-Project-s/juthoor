@php
    $treeSlug = isset($tree) && $tree ? $tree->slug : null;
    $currentSection = request()->route('section');
    $isFamilyAdmin = in_array($workspaceRole, ['admin', 'super_admin'], true);
@endphp
<nav aria-label="التنقل الإداري" class="workspace-nav">
@if($workspaceFamily)
<span class="workspace-nav-heading">نظرة عامة</span>
<a href="{{ route('management.families.dashboard',[$workspaceFamily->slug,'tree'=>$treeSlug]) }}" @if(request()->routeIs('management.families.dashboard')) aria-current="page" @endif><i class="bi bi-speedometer2"></i> لوحة العائلة</a>
<a href="{{ route('genealogy.tree',[$workspaceFamily->slug,'tree'=>$treeSlug,'private'=>1]) }}"><i class="bi bi-diagram-3"></i> الشجرة التفاعلية</a>
<span class="workspace-nav-heading">السجلات</span>
@foreach(['people'=>['الأشخاص','bi-person'],'relationships'=>['العلاقات','bi-link-45deg'],'branches'=>['الفروع','bi-bezier2'],'trees'=>['الأشجار','bi-tree']] as $key=>[$label,$icon])
<a href="{{ route('management.sections.index',[$workspaceFamily->slug,$key,'tree'=>$treeSlug]) }}" @if($currentSection===$key) aria-current="page" @endif><i class="bi {{ $icon }}"></i> {{ $label }}</a>
@endforeach
@can('viewAny',[\App\Models\CommunityEntity::class,$workspaceFamily])
<span class="workspace-nav-heading">الصفحات المجتمعية</span>
<a href="{{ route('management.entities.index',$workspaceFamily->slug) }}" @if(request()->routeIs('management.entities.*') && ! request()->routeIs('management.entities.review')) aria-current="page" @endif><i class="bi bi-diagram-2"></i> صفحات الكيانات</a>
<a href="{{ route('management.entities.review',$workspaceFamily->slug) }}" @if(request()->routeIs('management.entities.review')) aria-current="page" @endif><i class="bi bi-inbox"></i> مراجعة محتوى الكيانات</a>
@endcan
<span class="workspace-nav-heading">دليل الأعضاء</span>
<a href="{{ route('management.directory.edit',$workspaceFamily->slug) }}" @if(request()->routeIs('management.directory.edit')) aria-current="page" @endif><i class="bi bi-person-vcard"></i> ملفي في الدليل</a>
<a href="{{ route('directory.index',$workspaceFamily->slug) }}"><i class="bi bi-search"></i> تصفح الدليل</a>
@can('moderateDirectory',$workspaceFamily)<a href="{{ route('management.directory.review',$workspaceFamily->slug) }}" @if(request()->routeIs('management.directory.review')) aria-current="page" @endif><i class="bi bi-person-check"></i> مراجعة الدليل</a>@endcan
<span class="workspace-nav-heading">التوثيق والمراجعة</span>
<a href="{{ route('management.sources.index',[$workspaceFamily->slug,'tree'=>$treeSlug]) }}" @if(request()->routeIs('management.sources.*')) aria-current="page" @endif><i class="bi bi-journal-text"></i> المصادر والاستشهادات</a>
<a href="{{ route('management.changes.index',[$workspaceFamily->slug,'tree'=>$treeSlug]) }}" @if(request()->routeIs('management.changes.*')) aria-current="page" @endif><i class="bi bi-check2-square"></i> طلبات التغيير والمراجعة</a>
@can('manageSources',$workspaceFamily)<a href="{{ route('management.imports.index',[$workspaceFamily->slug,'tree'=>$treeSlug]) }}" @if(request()->routeIs('management.imports.*')) aria-current="page" @endif><i class="bi bi-upload"></i> استيراد CSV ومعاينة</a>@endcan
@can('manageSources',$workspaceFamily)<a href="{{ route('management.reconciliation.index',[$workspaceFamily->slug,'tree'=>$treeSlug]) }}" @if(request()->routeIs('management.reconciliation.*')) aria-current="page" @endif><i class="bi bi-spellcheck"></i> مطابقة الأسماء التاريخية</a>@endcan
@can('recover',$workspaceFamily)<a href="{{ route('management.recoveries.index',[$workspaceFamily->slug,'tree'=>$treeSlug]) }}" @if(request()->routeIs('management.recoveries.*')) aria-current="page" @endif><i class="bi bi-arrow-counterclockwise"></i> سجل الاسترجاع</a>@endcan
<a href="{{ route('management.merges.index',[$workspaceFamily->slug,'tree'=>$treeSlug]) }}" @if(request()->routeIs('management.merges.*')) aria-current="page" @endif><i class="bi bi-intersect"></i> دمج السجلات المكررة</a>
<a href="{{ route('management.sections.index',[$workspaceFamily->slug,'audit','tree'=>$treeSlug]) }}" @if($currentSection==='audit') aria-current="page" @endif><i class="bi bi-clock-history"></i> سجل التغييرات</a>
@if($isFamilyAdmin)
<span class="workspace-nav-heading">إدارة العائلة</span>
<a href="{{ route('management.sections.index',[$workspaceFamily->slug,'members','tree'=>$treeSlug]) }}" @if($currentSection==='members') aria-current="page" @endif><i class="bi bi-people"></i> الأعضاء</a>
@can('update',$workspaceFamily)<a href="{{ route('management.portal.edit',$workspaceFamily->slug) }}" @if(request()->routeIs('management.portal.*')) aria-current="page" @endif><i class="bi bi-window"></i> موقع العائلة</a>@endcan
@can('manageMembers',$workspaceFamily)<a href="{{ route('management.roles.index',[$workspaceFamily->slug,'tree'=>$treeSlug]) }}" @if(request()->routeIs('management.roles.*')) aria-current="page" @endif><i class="bi bi-shield-lock"></i> الأدوار والصلاحيات</a>@endcan
<a href="{{ route('management.sections.index',[$workspaceFamily->slug,'invitations','tree'=>$treeSlug]) }}" @if($currentSection==='invitations') aria-current="page" @endif><i class="bi bi-envelope"></i> الدعوات</a>
@can('manageMembers',$workspaceFamily)<a href="{{ route('management.attributes.index',$workspaceFamily->slug) }}" @if(request()->routeIs('management.attributes.*')) aria-current="page" @endif><i class="bi bi-tags"></i> صفات الأشخاص</a>@endcan
@can('manageMembers',$workspaceFamily)<a href="{{ route('cross-links.index',['family'=>$workspaceFamily->slug]) }}"><i class="bi bi-share"></i> مطابقات العائلات</a>@endcan
@endif
@endif
<span class="workspace-nav-heading">التنقل</span>
<a href="{{ route('management.families.index') }}" @if(request()->routeIs('management.families.index')) aria-current="page" @endif><i class="bi bi-grid"></i> كل عائلاتي</a>
@if(auth()->user()->isSuperAdmin())<a class="workspace-switch-link" href="{{ route('site-admin.dashboard') }}"><i class="bi bi-shield-lock"></i> الانتقال إلى إدارة الموقع</a>@endif
</nav>
