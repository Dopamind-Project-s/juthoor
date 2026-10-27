@props(['profile', 'family', 'fields'])
@php($has = fn (string $field) => in_array($field, $fields, true))
<article class="content-card directory-card sanad-member" data-profile="{{ $profile->id }}">
    <div class="sanad-member-identity">@if($has('photo') && $profile->photo_path)<img class="directory-photo" src="{{ route('directory.photo', [$family->slug, $profile->id]) }}" alt="صورة {{ $profile->display_name }}" loading="lazy" width="64" height="64">@else<span class="directory-photo is-empty" aria-hidden="true"><i class="bi bi-person"></i></span>@endif
    <div class="min-w-0"><h3 class="h6 fw-bold mb-1"><a href="{{ route('directory.show', [$family->slug, $profile->id]) }}">{{ $profile->display_name }}</a></h3>
    @if($has('profession') && ($profile->profession || $profile->specialization))<p class="small mb-1" data-field="profession">{{ collect([$profile->profession, $profile->specialization])->filter()->implode(' — ') }}</p>@endif
    @if($has('affiliation') && $profile->affiliation_status === 'verified' && $profile->entity)<span class="sanad-affiliation" data-field="affiliation"><i class="bi bi-patch-check" aria-hidden="true"></i> {{ $profile->entity->name_ar }} · انتماء موثق</span>@endif</div></div>
    <div class="sanad-member-body"><p class="small text-muted">@if($has('city') && $profile->city)<span data-field="city"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $profile->city }}</span>@endif @if($has('sector') && $profile->sector)<span data-field="sector"> · {{ \App\Models\MemberProfile::SECTORS[$profile->sector] }}</span>@endif</p>
    @if($has('skills') && $profile->skills)<div class="sanad-tags" data-field="skills">@foreach(array_slice($profile->skills, 0, 3) as $skill)<span>{{ $skill }}</span>@endforeach</div>@endif
    @if($has('business') && ($profile->services || $profile->business_name))<p class="small mt-3 mb-1" data-field="business">{{ \Illuminate\Support\Str::limit($profile->services ?: $profile->business_name, 110) }}</p>@endif</div>
    <div class="sanad-member-actions"><a class="btn btn-outline-primary btn-sm" href="{{ route('directory.show', [$family->slug, $profile->id]) }}">عرض الملف</a>@if($has('messages') && auth()->id() !== $profile->user_id)<a class="btn btn-primary btn-sm" href="{{ route('directory.show', [$family->slug, $profile->id]) }}#contact-request">طلب تواصل</a>@endif</div>
</article>
