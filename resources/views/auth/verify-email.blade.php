@extends('layouts.app')
@section('title','تأكيد البريد')
@section('content')<section class="section-space"><div class="container"><div class="auth-card mx-auto"><h1 class="h3">تأكيد البريد الإلكتروني</h1><p>أرسلنا رابطًا إلى بريدك. افتحه لتأكيد ملكية الحساب.</p>@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif<form method="post" action="{{ route('verification.send') }}">@csrf<button class="btn btn-primary">إعادة إرسال الرابط</button></form></div></div></section>@endsection
