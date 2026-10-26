@extends('layouts.management')
@section('page-heading','الإشعارات')
@section('title','الإشعارات')
@section('content')
<section class="management-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">الإشعارات</h1>
        <form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-sm btn-outline-primary">تعليم الكل مقروءًا</button></form>
    </div>
    <p class="small text-muted mt-2">إشعارات داخل المنصة فقط؛ لا تُرسل نصوص الإعلانات أو بيانات التواصل بالبريد.</p>
    <div class="management-list">
        @forelse($notifications as $notification)
            <article class="{{ $notification->read_at ? '' : 'is-unread' }}" data-notification="{{ $notification->data['kind'] ?? '' }}">
                <div><strong>{{ $notification->data['title'] ?? '' }}</strong><small>{{ $notification->data['body'] ?? '' }} · {{ $notification->created_at?->setTimezone(config('community.timezone'))->format('Y-m-d H:i') }}</small></div>
                <form method="post" action="{{ route('notifications.open', $notification->id) }}">@csrf<button class="btn btn-sm {{ $notification->read_at ? 'btn-outline-secondary' : 'btn-primary' }}">فتح</button></form>
            </article>
        @empty
            <p class="text-muted py-3" role="status">لا توجد إشعارات.</p>
        @endforelse
    </div>
    {{ $notifications->links() }}
</section>
@endsection
