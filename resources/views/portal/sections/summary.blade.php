<section class="portal-section portal-summary" id="portal-summary" aria-labelledby="portal-summary-title">
    <div class="portal-shell portal-split">
        <div><span class="portal-kicker">عن العائلة</span><h2 id="portal-summary-title">{{ $branding['display_name'] }}</h2>
            @if($branding['description'])<div class="portal-prose">{!! nl2br(e($branding['description'])) !!}</div>
            @elseif($branding['intro'])<p class="portal-prose">{{ $branding['intro'] }}</p>
            @else<p class="portal-empty">لم تُنشر نبذة تعريفية بعد. يضيفها مسؤول العائلة من إعدادات الموقع.</p>@endif</div>
        <aside class="portal-facts" aria-label="معلومات العائلة">
            @if($family->location)<p><i class="bi bi-geo-alt"></i> {{ $family->location }}</p>@endif
            @if($tree)<p><i class="bi bi-diagram-3"></i> {{ $tree->name_ar }}</p>@endif
            @if($rootEntity)<p><i class="bi bi-diagram-2"></i> {{ $rootEntity->type_label }}: <a href="{{ route('community.entities.show', [$family->slug, $rootEntity->slug]) }}">{{ $rootEntity->name_ar }}</a></p>@endif
        </aside>
    </div>
</section>
