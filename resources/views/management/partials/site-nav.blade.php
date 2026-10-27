<nav aria-label="تنقل إدارة الموقع" class="workspace-nav">
<span class="workspace-nav-heading">الموقع</span>
<a href="{{ route('site-admin.dashboard') }}" @if(request()->routeIs('site-admin.dashboard')) aria-current="page" @endif><i class="bi bi-speedometer2"></i> لوحة إدارة الموقع</a>
@foreach(['families'=>['العائلات','bi-house'],'trees'=>['الأشجار','bi-tree'],'users'=>['المستخدمون','bi-person-gear'],'memberships'=>['العضويات','bi-people'],'invitations'=>['الدعوات','bi-envelope'],'audit'=>['سجل التدقيق العام','bi-clock-history']] as $key=>[$label,$icon])
<a href="{{ route('site-admin.resources.index',$key) }}" @if(request()->routeIs('site-admin.resources.*') && request()->route('resource')===$key) aria-current="page" @endif><i class="bi {{ $icon }}"></i> {{ $label }}</a>
@endforeach
<span class="workspace-nav-heading">البيانات المرجعية والمراجعة</span>
<a href="{{ route('site-admin.roles.index') }}" @if(request()->routeIs('site-admin.roles.*')) aria-current="page" @endif><i class="bi bi-shield-lock"></i> الأدوار والصلاحيات</a>
<a href="{{ route('site-admin.seed-audit') }}" @if(request()->routeIs('site-admin.seed-audit')) aria-current="page" @endif><i class="bi bi-clipboard-data"></i> تدقيق البيانات والسيدرز</a>
<a href="{{ route('site-admin.domains.index') }}" @if(request()->routeIs('site-admin.domains.*')) aria-current="page" @endif><i class="bi bi-globe2"></i> نطاقات العائلات</a>
<a href="{{ route('site-admin.attributes.index') }}" @if(request()->routeIs('site-admin.attributes.*')) aria-current="page" @endif><i class="bi bi-tags"></i> صفات الأشخاص المشتركة</a>
<a href="{{ route('cross-links.index') }}" @if(request()->routeIs('cross-links.*')) aria-current="page" @endif><i class="bi bi-share"></i> مراجعة الروابط بين العائلات</a>
<span class="workspace-nav-heading">التنقل</span>
<a href="{{ route('management.families.index') }}"><i class="bi bi-grid"></i> مساحات العائلات</a>
</nav>
