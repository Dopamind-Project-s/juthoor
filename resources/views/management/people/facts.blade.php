@php
    $privacy = app(\App\Services\Genealogy\PersonPrivacy::class);
    $user = auth()->user();
    $canCite = $user->can('manageSources', $family);
    $canPropose = $user->can('propose', $family);
    $isAdmin = $user->can('publish', $family);
    $facts = \App\Models\Assertion::FACTS;
    $father = $person->outgoingRelationships->firstWhere(fn ($edge) => $edge->relationship_type === 'father' && $edge->is_primary)?->relatedPerson;
    $current = [
        'full_name' => $person->full_name_ar,
        'alternate_name' => $person->alternate_names ? implode('، ', $person->alternate_names) : null,
        'father' => $father?->display_name,
        'birth_year' => $person->birth_year, 'death_year' => $person->death_year,
        'title' => $person->title, 'honorific' => $person->honorific,
    ];
    $stateClasses = ['proposed' => 'text-bg-secondary', 'authoritative' => 'text-bg-success', 'alternative' => 'text-bg-info', 'rejected' => 'text-bg-danger', 'superseded' => 'text-bg-light'];
    $verificationLabels = ['pending_review' => 'مسجلة دون تحقق', 'sourced' => 'مسندة إلى مصدر', 'verified' => 'موثقة', 'unresolved' => 'محل خلاف'];
@endphp
<section class="management-panel" id="facts" aria-labelledby="factsHeading">
    <span class="eyebrow">الوقائع والروايات</span>
    <h2 class="h4" id="factsHeading">ما تقوله المصادر عن هذا الشخص</h2>
    <p class="text-muted">تسجيل رواية أو تهجئة لا يغيّر الشجرة. القيمة المعروضة تتغير فقط بعد اقتراح اعتماد يقبله مراجع، وتبقى الروايات السابقة والمرفوضة محفوظة مع أدلتها.</p>
    @if($sharedFamilies->isNotEmpty())
        <div class="alert alert-warning" role="status"><strong>جد مشترك:</strong> وقائع هذا الشخص تخص أيضًا {{ $sharedFamilies->pluck('name_ar')->implode('، ') }}. اعتماد أي تغيير عليها من صلاحية مشرف المنصة.</div>
    @endif

    @foreach($facts as $factKey => $fact)
        @php
            $claims = $assertions->get($factKey, collect());
            $currentVisible = $fact['privacy'] === null || $privacy->canView($person, $fact['privacy'], $user);
        @endphp
        @continue($claims->isEmpty() && $current[$factKey] === null)
        <article class="fact-group">
            <header><h3 class="h6 mb-1">{{ $fact['label'] }}</h3>
                <p class="mb-2"><span class="text-muted">المعتمد للعرض الآن:</span> @if($currentVisible)<strong>{{ $current[$factKey] ?? '—' }}</strong>@else<span class="text-muted"><i class="bi bi-lock"></i> محجوب</span>@endif</p></header>
            @foreach($claims as $claim)
                @php($claimVisible = $privacy->canViewFact($claim, $user))
                <div class="fact-claim">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <strong>@if($claimVisible){{ $claim->claim_text }}@else<span class="text-muted"><i class="bi bi-lock"></i> محجوب</span>@endif</strong>
                        <span class="badge {{ $stateClasses[$claim->state] }}">{{ \App\Models\Assertion::STATES[$claim->state] }}</span>
                        <span class="badge text-bg-light">{{ \App\Models\Assertion::RELATIONS[$claim->relation] ?? $claim->relation }}</span>
                        <span class="badge text-bg-light">{{ $verificationLabels[$claim->verification_status] ?? $claim->verification_status }}</span>
                        @if($claim->state === 'authoritative' && $claimVisible && (string) $claim->claim_text !== (string) $current[$factKey] && $factKey !== 'alternate_name')<span class="badge text-bg-warning">تغيرت القيمة المعروضة بعد اعتمادها</span>@endif
                    </div>
                    <small class="text-muted d-block">سجلها {{ $claim->creator?->name ?? 'استيراد/النظام' }} · {{ $claim->created_at?->format('Y-m-d') }}@if($claim->reviewed_at) · راجعها {{ $claim->reviewer?->name ?? 'النظام' }}: {{ $claim->review_reason }}@endif</small>
                    @if($claim->notes && $claimVisible)<p class="small mb-1">{{ $claim->notes }}</p>@endif
                    <ul class="small mb-1">
                        @forelse($claim->citations as $citation)
                            <li>@if($claimVisible)<a href="{{ route('management.sources.show',[$family->slug,$citation->source_id,'tree'=>$tree->slug]) }}">{{ $citation->source->title }}</a>{{ $citation->locator ? ' · '.$citation->locator : '' }} · {{ $verificationLabels[$citation->verification_status] ?? $citation->verification_status }}@else<i class="bi bi-lock"></i> دليل محجوب@endif</li>
                        @empty
                            <li class="text-muted">لا دليل مرفق بعد.</li>
                        @endforelse
                    </ul>
                    @if($claim->changeRequests->isNotEmpty())<a class="btn btn-sm btn-outline-primary" href="{{ route('management.changes.show',[$family->slug,$claim->changeRequests->first()->id,'tree'=>$tree->slug]) }}">مقترح الاعتماد قيد المراجعة</a>@endif
                    @if($claim->state === 'superseded' && $canPropose && $claimVisible && $claim->changeRequests->isEmpty())
                        <details class="mt-1"><summary class="small">إعادة اعتماد هذه الرواية</summary>
                            <form method="post" class="management-grid mt-2" action="{{ route('management.assertions.propose',[$family->slug,$claim->id,'tree'=>$tree->slug]) }}">@csrf
                                <label>سبب إعادة الاعتماد<textarea name="reason" class="form-control" required maxlength="2000"></textarea></label>
                                <button class="btn btn-sm btn-outline-primary">اقتراح إعادتها للعرض</button></form>
                        </details>
                    @endif
                    @if(! in_array($claim->state, ['rejected','superseded'], true))
                        <details class="mt-1"><summary class="small">إدارة الرواية</summary>
                            @if($canCite && $claimVisible)
                                <form method="post" class="management-grid mt-2" action="{{ route('management.assertions.update',[$family->slug,$claim->id,'tree'=>$tree->slug]) }}">@csrf @method('PUT')
                                    <label>العلاقة بالقيمة المعروضة<select name="relation" class="form-select">@foreach(\App\Models\Assertion::RELATIONS as $value=>$label)<option value="{{ $value }}" @selected($claim->relation===$value)>{{ $label }}</option>@endforeach</select></label>
                                    <label>حالة التحقق<select name="verification_status" class="form-select">@foreach($verificationLabels as $value=>$label)<option value="{{ $value }}" @selected($claim->verification_status===$value) @disabled($value==='verified' && ! $isAdmin && $claim->verification_status!=='verified')>{{ $label }}</option>@endforeach</select></label>
                                    <button class="btn btn-sm btn-outline-primary">حفظ التصنيف</button></form>
                                @if($factSources->isNotEmpty())
                                <form method="post" class="management-grid mt-2" action="{{ route('management.assertions.cite',[$family->slug,$claim->id,'tree'=>$tree->slug]) }}">@csrf
                                    <label>المصدر<select class="form-select" name="source_id" required>@foreach($factSources as $source)<option value="{{ $source->id }}">{{ $source->title }}</option>@endforeach</select></label>
                                    <label>الموضع (صفحة/لوحة)<input class="form-control" name="locator" maxlength="255"></label>
                                    <label>درجة دعم الدليل<select name="verification_status" class="form-select"><option value="pending_review">لم يُفحص</option><option value="sourced">يدعم الرواية</option><option value="unresolved">يخالفها أو غير واضح</option>@if($isAdmin)<option value="verified">موثق</option>@endif</select></label>
                                    <button class="btn btn-sm btn-outline-primary">إرفاق دليل</button></form>
                                @endif
                            @endif
                            @if($canPropose && $claim->state === 'proposed' && $claim->changeRequests->isEmpty())
                                <form method="post" class="management-grid mt-2" action="{{ route('management.assertions.propose',[$family->slug,$claim->id,'tree'=>$tree->slug]) }}">@csrf
                                    <label>سبب اقتراح الاعتماد<textarea name="reason" class="form-control" required maxlength="2000"></textarea></label>
                                    <button class="btn btn-sm btn-primary">{{ $factKey === 'alternate_name' || $claim->relation === 'alternate_spelling' ? 'اقتراح قبولها تهجئةً بديلة' : 'اقتراح اعتمادها للعرض' }}</button></form>
                            @endif
                        </details>
                    @endif
                </div>
            @endforeach
        </article>
    @endforeach

    @if($canPropose)
        <details class="management-form"><summary>تسجيل رواية أو تهجئة جديدة</summary>
            <form method="post" class="management-grid mt-3" action="{{ route('management.assertions.store',[$family->slug,$person->id,'tree'=>$tree->slug]) }}">@csrf
                <label>الواقعة<select name="fact_type" class="form-select" required>@foreach($facts as $value=>$fact)<option value="{{ $value }}" @selected(old('fact_type')===$value)>{{ $fact['label'] }}</option>@endforeach</select></label>
                <label>القيمة المروية<input class="form-control" name="value" value="{{ old('value') }}" maxlength="255" placeholder="مثال: ذبيان"></label>
                <label>الأب المروي (لواقعة الأب)<select name="related_person_id" class="form-select" data-remote-select data-remote-url="{{ route('management.lookups.people',[$family->slug,'tree'=>$tree->slug]) }}"><option value="">—</option></select></label>
                <label>العلاقة بالقيمة المعروضة<select name="relation" class="form-select"><option value="">تلقائي</option>@foreach(\App\Models\Assertion::RELATIONS as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label>ملاحظات الرواية<textarea name="notes" class="form-control" maxlength="2000">{{ old('notes') }}</textarea></label>
                <button class="btn btn-primary">تسجيل الرواية</button>
            </form>
        </details>
    @endif
</section>
