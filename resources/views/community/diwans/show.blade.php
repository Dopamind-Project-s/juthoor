@extends('layouts.family-site')
@section('title', $diwan->name)
@section('description', \Illuminate\Support\Str::limit($diwan->description ?: $diwan->name, 160))
@section('nav-current', 'entities')
@php($hero = $images->first())
{{-- A private residence shows no address or directions without recorded consent (also enforced at publication). --}}
@php($placeShown = ! $diwan->is_private_residence || $diwan->residence_consent)
@section('content')
<section class="j-hero {{ $hero ? 'has-cover' : '' }}" aria-labelledby="diwan-title">
    @if($hero)<img class="j-hero-cover" src="{{ route('media.show', [$hero->uuid, 'display']) }}" alt="{{ $hero->alt_text ?: $diwan->name }}">@endif
    <div class="j-shell">
        <ol class="j-breadcrumb" aria-label="مسار التنقل"><li><a href="{{ $portalUrl }}">{{ $branding['display_name'] }}</a></li>@if($entities->first())<li><a href="{{ route('community.entities.show', [$family->slug, $entities->first()->slug]) }}#diwans">{{ $entities->first()->name_ar }}</a></li>@endif<li aria-current="page">{{ $diwan->name }}</li></ol>
        <span class="j-pill on-dark">{{ \App\Models\Diwan::KINDS[$diwan->kind] ?? $diwan->kind }}</span>
        @if($preview)<span class="j-pill">معاينة — غير منشور للعامة</span>@endif
        <h1 id="diwan-title">{{ $diwan->name }}</h1>
        @if($diwan->place_label)<p class="j-hero-lead"><i class="bi bi-geo-alt"></i> {{ $diwan->place_label }}</p>@endif
        <div class="j-hero-actions">@if($placeShown && ($directions = $diwan->directionsUrl()))<a class="j-btn j-btn-accent" href="{{ $directions }}" target="_blank" rel="noopener" data-directions><i class="bi bi-sign-turn-right"></i> الاتجاهات في Google Maps</a>@endif
            @if($entities->first())<a class="j-btn j-btn-on-dark" href="{{ route('community.entities.show', [$family->slug, $entities->first()->slug]) }}#diwans">كل دواوين {{ $entities->first()->name_ar }}</a>@endif</div>
    </div>
</section>
<section class="j-section"><div class="j-shell diwan-detail">
    <div class="diwan-main">
        @if($diwan->description)<div class="j-prose mb-4">{!! nl2br(e($diwan->description)) !!}</div>@else<x-ui.empty icon="house-door" title="لا يوجد وصف منشور بعد." />@endif
        @if($images->count() > 1)<h2 class="h5 fw-bold mt-4">معرض الصور</h2><div class="j-gallery mb-4">@foreach($images as $image)<figure data-media="{{ $image->id }}"><img src="{{ route('media.show', [$image->uuid, 'display']) }}" alt="{{ $image->alt_text ?: $diwan->name }}" loading="lazy" width="320" height="240"><figcaption>{{ $image->title }}</figcaption></figure>@endforeach</div>@endif
        @if($placeShown && ($embed = $diwan->embedUrl()))<iframe class="diwan-map w-100 rounded-3" src="{{ $embed }}" title="خريطة {{ $diwan->name }}" loading="lazy" height="320" referrerpolicy="no-referrer-when-downgrade"></iframe>@endif
        <x-community.evidence :record="$diwan" />
    </div>
    <aside class="d-grid gap-3 align-content-start">
        <section class="j-card"><h2 class="h6 fw-bold">معلومات الزيارة</h2><dl class="entity-facts mb-0">
            @if($diwan->place_label)<div><dt>المنطقة</dt><dd>{{ $diwan->place_label }}</dd></div>@endif
            @if($placeShown && $diwan->public_address)<div><dt>العنوان العام</dt><dd>{{ $diwan->public_address }}</dd></div>@endif
            @if($diwan->visit_instructions)<div><dt>تعليمات الزيارة</dt><dd>{{ $diwan->visit_instructions }}</dd></div>@endif
            @if($diwan->contact_consent && ($diwan->contact_name || $diwan->contact_phone))<div data-diwan-contact><dt>للتواصل (بموافقة مسجلة)</dt><dd>{{ $diwan->contact_name }} @if($diwan->contact_phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $diwan->contact_phone) }}" dir="ltr">{{ $diwan->contact_phone }}</a>@endif</dd></div>@endif
        </dl>@if(! $diwan->place_label && ! $diwan->public_address)<p class="small text-muted mb-0">لا يُنشر موقع دقيق دون إذن مسجل.</p>@endif</section>
        @if($entities->isNotEmpty())<section class="j-card"><h2 class="h6 fw-bold">يخص</h2><ul class="list-unstyled mb-0">@foreach($entities as $entity)<li data-related-entity="{{ $entity->slug }}"><a class="j-link" href="{{ route('community.entities.show', [$family->slug, $entity->slug]) }}">{{ $entity->name_ar }}</a> <span class="small text-muted">{{ $entity->type_label }}</span></li>@endforeach</ul></section>@endif
        @if($others->isNotEmpty())<section class="j-card"><h2 class="h6 fw-bold">دواوين أخرى</h2><ul class="list-unstyled mb-0">@foreach($others as $other)<li><a class="j-link" href="{{ route('community.diwans.show', [$family->slug, $other->id]) }}">{{ $other->name }}</a></li>@endforeach</ul></section>@endif
    </aside>
</div></section>
@endsection
