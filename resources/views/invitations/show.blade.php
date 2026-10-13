@extends('layouts.app')
@section('title', 'دعوة عائلية')
@section('content')
<section class="section-space"><div class="container"><div class="auth-card mx-auto">
    <span class="eyebrow">دعوة عضوية</span>
    <h1 class="h3">{{ $invitation->family->name_ar }}</h1>
    <p>الدور المقترح: <strong>{{ ['reader'=>'قارئ','editor'=>'محرر','admin'=>'مدير'][$invitation->role] }}</strong></p>
    <p class="text-muted">الدعوة مخصصة للبريد {{ $invitation->email }} وتنتهي {{ $invitation->expires_at->format('Y-m-d H:i') }}.</p>
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    @if(!$invitation->isAcceptable())
        <div class="alert alert-warning">هذه الدعوة غير متاحة: انتهت أو أُلغيت أو قُبلت أو عائلتها معطلة. اطلب دعوة جديدة من المدير.</div>
    @else
        @auth
            <form method="post" action="{{ route('invitations.accept', $token) }}">
                @csrf
                <button class="btn btn-primary w-100">قبول الدعوة</button>
            </form>
        @else
            @if($existingUser)
                <a class="btn btn-primary w-100" href="{{ route('login', ['invitation'=>$token]) }}">سجل الدخول بالبريد المدعو</a>
            @else
                <a class="btn btn-primary w-100" href="{{ route('invitations.register', $token) }}">إنشاء حساب وقبول الدعوة</a>
            @endif
        @endauth
    @endif
</div></div></section>
@endsection
