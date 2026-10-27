<section class="portal-section portal-genealogy" id="portal-genealogy" aria-labelledby="portal-genealogy-title">
    <div class="portal-shell portal-split">
        <div><span class="portal-kicker">الشجرة الجامعة</span><h2 id="portal-genealogy-title">نسب موحّد بلا تكرار</h2>
            <p class="portal-prose">شجرة واحدة لكل العائلة تشترك فيها الأفخاذ في أجدادها، وتظهر للعامة السجلات المنشورة وحدها مع حالة توثيق كل سجل.</p>
            @if($tree)<a class="btn portal-btn-primary" href="{{ route('genealogy.tree', [$family->slug, 'tree' => $tree->slug]) }}"><i class="bi bi-diagram-3"></i> افتح الشجرة الجامعة</a>@endif</div>
        <div class="portal-mini-tree" aria-label="معاينة النسب">
            @if($genealogyPreview && $genealogyPreview['founder'])
                <a class="portal-mini-founder" href="{{ route('genealogy.person', [$family->slug, $genealogyPreview['founder']->id, 'tree' => $genealogyPreview['tree']->slug]) }}">{{ $genealogyPreview['founder']->full_name_ar }}</a>
                @if(collect($genealogyPreview['children'])->isNotEmpty())<ul>@foreach(collect($genealogyPreview['children'])->take(8) as $child)<li><a href="{{ route('genealogy.person', [$family->slug, $child->id, 'tree' => $genealogyPreview['tree']->slug]) }}">{{ $child->full_name_ar }}</a></li>@endforeach</ul>@endif
            @else
                <p class="portal-empty">لا يوجد جذر منشور لمعاينته بعد.</p>
            @endif
        </div>
    </div>
</section>
