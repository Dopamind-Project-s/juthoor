@extends('layouts.app')
@section('title', $post->title)
@php($zone = config('community.timezone'))
@section('content')
<section class="branch-hero entity-hero">
    <div class="container">
        <nav class="family-breadcrumb light" aria-label="مسار"><a href="{{ route('community.entities.show', [$family->slug, $post->ownerEntity->slug]) }}">{{ $post->ownerEntity->name_ar }}</a><i class="bi bi-chevron-left" aria-hidden="true"></i><a href="{{ route('community.entities.news', [$family->slug, $post->ownerEntity->slug]) }}">الأخبار</a></nav>
        <div class="d-flex flex-wrap gap-2 mt-3"><span class="status-badge light-badge">{{ \App\Models\NewsPost::CATEGORIES[$post->category] }}</span>@if($post->cancelled_at)<span class="badge text-bg-danger" data-cancelled>ملغى</span>@endif @if($preview && $post->publication_status !== 'published')<span class="status-badge unverified">معاينة · {{ $post->status_label }}</span>@endif</div>
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
</div>
@endsection
