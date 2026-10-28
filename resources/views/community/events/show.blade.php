@extends('layouts.family-site')
@section('nav-current', 'heritage')
@section('title', $event->title)
@php($start = $event->localStart())
@php($end = $event->localEnd())
@push('styles')<link rel="stylesheet" href="{{ asset('css/community-publications.css') }}">@endpush
@push('scripts')<script src="{{ asset('js/community-publications.js') }}" defer></script>@endpush
@section('content')
<div class="container pt-3"><button type="button" class="btn btn-outline-secondary" data-publication-share>مشاركة الرابط</button><span class="small ms-2" role="status" data-share-status></span></div>
<section class="branch-hero entity-hero">
    <div class="container">
        <nav class="family-breadcrumb light" aria-label="مسار"><a href="{{ route('community.entities.show', [$family->slug, $event->ownerEntity->slug]) }}">{{ $event->ownerEntity->name_ar }}</a><i class="bi bi-chevron-left" aria-hidden="true"></i><a href="{{ route('community.entities.calendar', [$family->slug, $event->ownerEntity->slug]) }}">التقويم</a></nav>
        <div class="d-flex flex-wrap gap-2 mt-3"><span class="status-badge light-badge">{{ \App\Models\CommunityEvent::CATEGORIES[$event->category] }}</span><span class="badge {{ ['cancelled' => 'text-bg-danger', 'postponed' => 'text-bg-warning', 'completed' => 'text-bg-secondary'][$event->display_status] ?? 'text-bg-success' }}" data-event-status="{{ $event->display_status }}">{{ \App\Models\CommunityEvent::STATUSES[$event->display_status] }}</span></div>
        <h1>{{ $event->title }}</h1>
        <p class="entity-summary"><time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->all_day ? $start->format('Y-m-d') : $start->format('Y-m-d H:i') }}</time>@if($end) — <time datetime="{{ $event->ends_at->toIso8601String() }}">{{ $event->all_day ? $end->format('Y-m-d') : $end->format('Y-m-d H:i') }}</time>@endif <span dir="ltr">({{ $event->timezone }})</span></p>
    </div>
</section>
<div class="container py-4">
    <div class="row g-4">
        <article class="col-lg-8 d-grid gap-3 align-content-start">
            <x-community.notices :notices="$event->notices" />
            @if($event->description)<section class="entity-section"><div class="entity-description">{!! nl2br(e($event->description)) !!}</div></section>@endif
            <x-community.media-strip :attachments="$event->mediaAttachments" :visible-media="$visibleMedia" />
            @if($announcements->isNotEmpty())<section class="entity-section"><h2 class="h6 fw-bold">إعلانات مرتبطة</h2><ul class="mb-0">@foreach($announcements as $announcement)<li><a href="{{ route('community.news.show', [$family->slug, $announcement->id]) }}">{{ $announcement->title }}</a></li>@endforeach</ul></section>@endif
        </article>
        <aside class="col-lg-4 d-grid gap-3 align-content-start">
            @if($relatedEntities->isNotEmpty())<nav class="entity-aside" aria-label="المجتمعات المرتبطة"><h2 class="h6 fw-bold">المجتمعات المرتبطة</h2>@foreach($relatedEntities as $relatedEntity)<a class="d-block" href="{{ route('community.entities.show', [$family->slug, $relatedEntity->slug]) }}">{{ $relatedEntity->name_ar }}</a>@endforeach</nav>@endif
            <section class="entity-aside">
                <dl class="entity-facts mb-0">
                    @if($event->organizer)<div><dt>المنظم</dt><dd>{{ $event->organizer }}</dd></div>@endif
                    @if($event->location_name || $event->place_label)<div><dt>المكان</dt><dd>{{ $event->location_name }} {{ $event->public_address }} {{ $event->place_label }}</dd></div>@endif
                    @if($event->hasPublicContact())<div data-public-contact><dt>للاستفسار</dt><dd>{{ $event->contact_name }} @if($event->contact_phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $event->contact_phone) }}" dir="ltr">{{ $event->contact_phone }}</a>@endif</dd></div>@endif
                </dl>
                @if($event->display_status !== 'cancelled' && ($directions = $event->directionsUrl()))<a class="btn btn-sm btn-primary mt-2" href="{{ $directions }}" target="_blank" rel="noopener" data-directions><i class="bi bi-sign-turn-right"></i> فتح الاتجاهات في Google Maps</a>@endif
            </section>
            @if($people->isNotEmpty())
                <section class="entity-aside" data-linked-people><h2 class="h6 fw-bold">أشخاص مذكورون</h2><ul class="inline-list">@foreach($people as $person)<li>@if(isset($publicPeople[$person->id]))<a href="{{ route('genealogy.person', [$family->slug, $person->id]) }}">{{ $person->full_name_ar }}</a>@else{{ $person->full_name_ar }}@endif</li>@endforeach</ul></section>
            @endif
            <section class="entity-aside"><x-community.evidence :record="$event" :entity="$event->ownerEntity" /></section>
        </aside>
    </div>
</div>
@endsection
