<header class="site-header"><nav class="navbar navbar-expand-lg"><div class="container">
<a class="brand" href="{{ route('home') }}"><span class="brand-mark"><i class="bi bi-tree-fill"></i></span><span>جذور<small>Juthoor</small></span></a>
<button class="navbar-toggler border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-label="فتح القائمة"><i class="bi bi-list fs-2"></i></button>
<div class="collapse navbar-collapse"><ul class="navbar-nav mx-auto">
<li class="nav-item"><a class="nav-link active" href="{{ route('home') }}">الرئيسية</a></li><li><a class="nav-link" href="{{ route('home') }}#about">عن جذور</a></li><li><a class="nav-link" href="{{ route('family.home') }}">استكشف العشيرة</a></li><li><a class="nav-link" href="#contact">تواصل معنا</a></li></ul>
<div class="desktop-actions d-flex gap-2">
@auth
    @if(auth()->user()->isSuperAdmin())<a href="{{ route('site-admin.dashboard') }}" class="btn btn-outline-primary">إدارة الموقع</a>@endif
    <a href="{{ route('management.families.index') }}" class="btn btn-primary">إدارة عائلاتي</a>
@else
    <a href="{{ route('login') }}" class="btn btn-outline-primary">تسجيل الدخول</a>
@endauth
</div></div></div></nav></header>
<div class="offcanvas offcanvas-end" tabindex="-1" id="mobileMenu"><div class="offcanvas-header border-bottom"><a class="brand" href="/"><span class="brand-mark"><i class="bi bi-tree-fill"></i></span><span>جذور</span></a><button class="btn-close ms-0 me-auto" data-bs-dismiss="offcanvas" aria-label="إغلاق"></button></div><div class="offcanvas-body"><nav class="d-grid gap-2"><a class="nav-link" href="/">الرئيسية</a><a class="nav-link" href="/#about">عن جذور</a><a class="nav-link" href="/al-zoubi">استكشف عشيرة الزعبي</a>@auth<a class="nav-link" href="{{ route('management.families.index') }}">إدارة عائلاتي</a>@else<a class="nav-link" href="{{ route('login') }}">تسجيل الدخول</a>@endauth<a class="nav-link" href="#contact">تواصل معنا</a></nav></div></div>
