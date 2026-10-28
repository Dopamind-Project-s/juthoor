@extends('layouts.family-site')
@section('nav-current', 'news')
@section('title', $post->title)
@php($zone = config('community.timezone'))
@push('styles')<link rel="stylesheet" href="{{ asset('css/community-publications.css') }}">@endpush
@push('scripts')<script src="{{ asset('js/community-publications.js') }}" defer></script>@endpush
@section('content')
<div class="container pt-3"><button type="button" class="btn btn-outline-secondary" data-publication-share>مشاركة الرابط</button><span class="small ms-2" role="status" data-share-status></span></div>
<section class="branch-hero entity-hero {{ $post->isSensitive() ? 'publication-memorial' : '' }}">
    <div class="container">
        <nav class="family-breadcrumb light" aria-label="مسار"><a href="{{ route('community.entities.show', [$family->slug, $post->ownerEntity->slug]) }}">{{ $post->ownerEntity->name_ar }}</a><i class="bi bi-chevron-left" aria-hidden="true"></i><a href="{{ route('community.entities.news', [$family->slug, $post->ownerEntity->slug]) }}">الأخبار</a></nav>
        <div class="d-flex flex-wrap gap-2 mt-3"><span class="status-badge light-badge">{{ \App\Models\NewsPost::CATEGORIES[$post->category] }}</span>@if($post->cancelled_at)<span class="badge text-bg-danger" data-cancelled>ملغى</span>@endif @if($preview && $post->publication_status !== 'published')<span class="status-badge unverified">معاينة · {{ $post->status_label }}</span>@endif</div>
        @if(in_array($post->category, ['congratulation', 'graduation', 'wedding', 'achievement', 'appointment', 'award'], true))<p class="publication-occasion">أفراح وإنجازات أبناء العشيرة</p>@elseif($post->isSensitive())<p class="publication-occasion">وفيات وتعازي</p>@endif
        <h1>{{ $post->title }}</h1>
        @if($post->summary)<p class="entity-summary">{{ $post->summary }}</p>@endif
    </div>
</section>
<div class="container py-4">
    <div class="row g-4">
        <article class="col-lg-8 d-grid gap-3 align-content-start">
            <x-community.notices :notices="$post->notices" />
            <section class="entity-section"><div class="entity-description">{!! nl2br(e($post->body)) !!}</div></section>
            @if($post->isSensitive() && $post->family_consent)
                <section class="entity-section" data-death-details>
                    <h2 class="h6 fw-bold">{{ $post->category === 'death' ? 'بيانات النعي' : 'بيانات العزاء' }}</h2>
                    <dl class="entity-facts mb-0">
                        @if($post->deceased_name)<div><dt>المتوفى</dt><dd>@if($post->deceased_person_id && isset($publicPeople[$post->deceased_person_id]))<a href="{{ route('genealogy.person', [$family->slug, $post->deceased_person_id]) }}">{{ $post->deceased_name }}</a>@else{{ $post->deceased_name }}@endif</dd></div>@endif
                        @if($post->death_date)<div><dt>تاريخ الوفاة</dt><dd>{{ $post->death_date->format('Y-m-d') }}</dd></div>@endif
                        @if($post->funeral_prayer)<div><dt>صلاة الجنازة</dt><dd>{{ $post->funeral_prayer }}</dd></div>@endif
                        @if($post->burial_place)<div><dt>الدفن</dt><dd>{{ $post->burial_place }}</dd></div>@endif
                        @if($post->condolence_place)<div><dt>استقبال العزاء</dt><dd>{{ $post->condolence_place }}</dd></div>@endif
                        @if($post->condolence_times)<div><dt>مواعيد العزاء</dt><dd>{{ $post->condolence_times }}</dd></div>@endif
                    </dl>
                </section>
            @endif
            @if($post->event)
                <section class="entity-section" data-linked-event><h2 class="h6 fw-bold">المناسبة المرتبطة</h2><x-community.event-card :event="$post->event" :family="$family" /></section>
            @endif
            <x-community.media-strip :attachments="$post->mediaAttachments" :visible-media="$visibleMedia" />
        </article>
        <aside class="col-lg-4 d-grid gap-3 align-content-start">
            @if($relatedEntities->isNotEmpty())<nav class="entity-aside" aria-label="المجتمعات المرتبطة"><h2 class="h6 fw-bold">المجتمعات المرتبطة</h2>@foreach($relatedEntities as $relatedEntity)<a class="d-block" href="{{ route('community.entities.show', [$family->slug, $relatedEntity->slug]) }}">{{ $relatedEntity->name_ar }}</a>@endforeach</nav>@endif
            <section class="entity-aside">
                <dl class="entity-facts mb-0">
                    <div><dt>تاريخ النشر</dt><dd>{{ ($post->publish_at ?? $post->reviewed_at ?? $post->created_at)?->setTimezone($zone)->format('Y-m-d H:i') }}</dd></div>
                    @if($post->expires_at)<div><dt>ينتهي عرضه</dt><dd>{{ $post->expires_at->setTimezone($zone)->format('Y-m-d H:i') }}</dd></div>@endif
                    @if($post->location_name || $post->place_label)<div><dt>المكان</dt><dd>{{ $post->location_name }} {{ $post->public_address }} {{ $post->place_label }}</dd></div>@endif
                    @if($post->hasPublicContact())<div data-public-contact><dt>للاستفسار</dt><dd>{{ $post->contact_name }} @if($post->contact_phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $post->contact_phone) }}" dir="ltr">{{ $post->contact_phone }}</a>@endif</dd></div>@endif
                </dl>
                @if($directions = $post->directionsUrl())<a class="btn btn-sm btn-primary mt-2" href="{{ $directions }}" target="_blank" rel="noopener" data-directions><i class="bi bi-sign-turn-right"></i> فتح الاتجاهات في Google Maps</a>@endif
            </section>
            @if($people->isNotEmpty())
                <section class="entity-aside" data-linked-people><h2 class="h6 fw-bold">أشخاص مذكورون</h2><ul class="inline-list">@foreach($people as $person)<li>@if(isset($publicPeople[$person->id]))<a href="{{ route('genealogy.person', [$family->slug, $person->id]) }}">{{ $person->full_name_ar }}</a>@else{{ $person->full_name_ar }}@endif</li>@endforeach</ul></section>
            @endif
            <section class="entity-aside"><x-community.evidence :record="$post" :entity="$post->ownerEntity" /></section>
        </aside>
    </div>
    @if($relatedPosts->isNotEmpty())<section class="mt-4" aria-label="أخبار ذات صلة"><h2 class="h4">أخبار من نفس المجتمع</h2><div class="publication-grid">@foreach($relatedPosts as $relatedPost)<x-community.news-card :post="$relatedPost" :family="$family" />@endforeach</div></section>@endif
</div>
@endsection
