@props(['family', 'branding', 'nav' => [], 'onFamilyHost' => false])
<footer class="portal-footer">
    <div class="portal-shell portal-footer-grid">
        <div><strong class="portal-footer-name">{{ $branding['display_name'] }}</strong>
            @if($branding['footer_text'])<p>{{ $branding['footer_text'] }}</p>@endif
            <p class="small">السجلات المنشورة تخضع لمراجعة العائلة؛ وجود سجل لا يعني توثيقه تاريخيًا، وغياب شخص لا يعني عدم انتمائه.</p></div>
        <div>@if($branding['contact_links'] || $branding['public_email'])<h2 class="h6">تواصل</h2><ul class="list-unstyled">@foreach($branding['contact_links'] as $link)<li><a href="{{ $link['url'] }}" rel="noopener nofollow" target="_blank">{{ $link['label'] }}</a></li>@endforeach @if($branding['public_email'])<li><span dir="ltr">{{ $branding['public_email'] }}</span></li>@endif</ul>@endif</div>
        <div><h2 class="h6">روابط</h2><ul class="list-unstyled">@foreach($nav as $item)<li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>@endforeach</ul></div>
    </div>
    <div class="portal-shell portal-platform-credit">موقع العائلة على <a href="{{ $onFamilyHost ? app(\App\Services\Portal\FamilyHosts::class)->origin(config('portals.platform_host')).'/' : route('home') }}">منصة جذور</a> لتوثيق الأنساب.</div>
</footer>
