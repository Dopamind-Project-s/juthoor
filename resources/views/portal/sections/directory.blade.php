<section class="portal-section portal-directory" id="portal-directory" aria-labelledby="portal-directory-title">
    <div class="portal-shell">
        <span class="portal-kicker">دليل السند</span><h2 id="portal-directory-title">من دليل الأعضاء</h2>
        @if($directory->isEmpty())<p class="portal-empty">لم يختر أي عضو بعد إظهار ملفه للعامة. الدليل طوعي، ويتحكم كل عضو في حقوله.</p>
        @else<div class="portal-card-row">@foreach($directory as $entry)@php($profile = $entry['profile'])
            <article class="portal-card portal-member" data-profile="{{ $profile->id }}"><span class="portal-member-avatar" aria-hidden="true"><i class="bi bi-person"></i></span><h3>{{ $profile->display_name }}</h3>
            @if(in_array('profession', $entry['fields'], true) && $profile->profession)<p>{{ $profile->profession }}</p>@endif
            @if(in_array('city', $entry['fields'], true) && $profile->city)<p class="small text-muted"><i class="bi bi-geo-alt"></i> {{ $profile->city }}</p>@endif</article>
        @endforeach</div>@endif
        <a class="portal-more" href="{{ route('directory.index', $family->slug) }}">تصفح الدليل <i class="bi bi-arrow-left"></i></a>
    </div>
</section>
