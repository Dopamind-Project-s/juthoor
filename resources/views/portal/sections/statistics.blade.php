<section class="portal-section portal-stats" id="portal-statistics" aria-labelledby="portal-stats-title">
    <div class="portal-shell">
        <span class="portal-kicker">من قاعدة البيانات</span><h2 id="portal-stats-title">السجلات المنشورة</h2>
        <dl class="portal-stat-grid">
            @foreach(['published' => ['أشخاص منشورون', 'people'], 'verified' => ['منهم موثقون', 'patch-check'], 'registered' => ['سجلات نسب مسجلة', 'journal-text'], 'branches' => ['فروع منشورة', 'diagram-2'], 'fakhds' => ['أفخاذ وفروع تعريفية', 'diagram-3'], 'diwans' => ['دواوين ومضافات', 'house-door'], 'archive' => ['وثائق في الأرشيف', 'archive'], 'achievements' => ['إنجازات معتمدة', 'award']] as $key => [$label, $icon])
            <div class="portal-stat" data-stat="{{ $key }}"><dt><i class="bi bi-{{ $icon }}" aria-hidden="true"></i> {{ $label }}</dt><dd>{{ number_format($statistics[$key]) }}</dd></div>
            @endforeach
        </dl>
        <p class="portal-disclaimer">الأرقام عدد السجلات في جذور حتى {{ $statistics['computedAt']->setTimezone(config('community.timezone'))->format('Y-m-d H:i') }}، وليست تعدادًا لأفراد العائلة؛ نسبة التغطية غير معروفة. الأحياء ومجهولو الحالة لا يُعرضون للعامة.</p>
    </div>
</section>
