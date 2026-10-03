@extends('layouts.app')
@section('title','تسجيل الدخول')
@section('content')
<section class="section-space"><div class="container"><div class="auth-card mx-auto">
    <span class="eyebrow">إدارة العائلات</span><h1 class="h2 fw-bold">تسجيل الدخول</h1>
    <p class="text-muted">استخدم حسابك للوصول إلى العائلات التي تملك عضوية فيها.</p>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('login.store') }}">@csrf @if(request('invitation'))<input type="hidden" name="invitation" value="{{ request('invitation') }}">@endif
        <label class="form-label" for="email">البريد الإلكتروني</label><input class="form-control mb-3" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
        <label class="form-label" for="password">كلمة المرور</label><input class="form-control mb-3" id="password" name="password" type="password" required>
        <label class="d-flex gap-2 mb-3"><input type="checkbox" name="remember" value="1"> تذكرني</label>
        <button class="btn btn-primary w-100">دخول</button><a class="d-block text-center mt-3" href="{{ route('password.request') }}">نسيت كلمة المرور؟</a>
    </form>
</div></div></section>
@endsection
