@props(['profile', 'family', 'fields'])
@php($has = fn (string $field) => in_array($field, $fields, true))
<article class="content-card directory-card" data-profile="{{ $profile->id }}">
    @if($has('photo') && $profile->photo_path)<img class="directory-photo" src="{{ route('directory.photo', [$family->slug, $profile->id]) }}" alt="صورة {{ $profile->display_name }}" loading="lazy" width="64" height="64">@else<span class="directory-photo is-empty" aria-hidden="true"><i class="bi bi-person"></i></span>@endif
    <div class="min-w-0">
        <h3 class="h6 fw-bold mb-1"><a href="{{ route('directory.show', [$family->slug, $profile->id]) }}">{{ $profile->display_name }}</a></h3>
        @if($has('profession') && ($profile->profession || $profile->specialization))<p class="small mb-1" data-field="profession">{{ collect([$profile->profession, $profile->specialization])->filter()->implode(' — ') }}</p>@endif
        <p class="small text-muted mb-0">
            @if($has('affiliation') && $profile->affiliation_status === 'verified' && $profile->entity)<span data-field="affiliation"><i class="bi bi-patch-check"></i> {{ $profile->entity->name_ar }}</span>@endif
            @if($has('city') && $profile->city)<span data-field="city"> · {{ $profile->city }}</span>@endif
            @if($has('sector') && $profile->sector)<span data-field="sector"> · {{ \App\Models\MemberProfile::SECTORS[$profile->sector] }}</span>@endif
        </p>
    </div>
</article>
