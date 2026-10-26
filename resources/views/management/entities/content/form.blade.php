@extends('layouts.management')
@php
    $moduleInfo = \App\Services\Community\CommunityContentModules::MODULES[$module];
    $v = fn (string $field, $default = null) => old($field, $record->{$field} ?? $default);
    $selectedSubjects = array_map('intval', old('subjects', $subjects));
    $selectedAudiences = array_map('intval', old('audiences', $audiences));
    $locked = $record->exists && ! $canPublish && in_array($record->publication_status, ['published', 'archived'], true);
    $actionLabels = ['created' => 'إنشاء', 'updated' => 'تعديل', 'submitted' => 'إرسال للمراجعة', 'review_publish' => 'نشر', 'review_decline' => 'إعادة إلى المسودة', 'review_archive' => 'أرشفة', 'review_restore' => 'استعادة من الأرشيف', 'deleted' => 'حذف'];
@endphp
@section('page-heading', $moduleInfo['label'])
@section('title', ($record->exists ? $record->content_title : 'سجل جديد').' — '.$entity->name_ar)
@section('content')
<section class="management-panel">
    <span class="eyebrow"><a href="{{ route('management.entities.edit', [$family->slug, $entity->id]) }}">{{ $entity->name_ar }}</a> · <a href="{{ route('management.entities.content.index', [$family->slug, $entity->id, $module]) }}">{{ $moduleInfo['label'] }}</a></span>
    <h1 class="h4">{{ $record->exists ? $record->content_title : 'سجل جديد' }}</h1>
    @if($record->exists)<p class="text-muted mb-0" data-record-status="{{ $record->publication_status }}">{{ $record->status_label }} · {{ $record->verification_label }}@if($record->reviewed_at) · آخر مراجعة {{ $record->reviewed_at->format('Y-m-d') }}@endif</p>@endif
    @if($record->review_note)<p class="alert alert-info mt-2 mb-0" data-review-note>ملاحظة المراجعة: {{ $record->review_note }}</p>@endif
    @if(! $canPublish)<p class="alert alert-light mt-2 mb-0">احفظ السجل مسودة ثم أرسله للمراجعة. النشر واعتماد التوثيق وتعديل المنشور من صلاحية مدير العائلة أو مدير الكيان.</p>@endif
    @if($locked)<p class="alert alert-warning mt-2 mb-0">هذا السجل منشور أو مؤرشف؛ لا يمكنك تعديله.</p>@endif
</section>

<section class="management-panel">
    <form method="post" action="{{ $record->exists ? route('management.entities.content.update', [$family->slug, $entity->id, $module, $record->id]) : route('management.entities.content.store', [$family->slug, $entity->id, $module]) }}" class="management-grid entity-form" data-content-form>
        @csrf
        @if($record->exists) @method('PUT') @endif
        @include('management.entities.content.fields.'.$module)
        <fieldset class="content-governance">
            <legend class="h6">الربط والنشر</legend>
            <div class="content-entity-choices">
                <span class="fw-bold">الكيانات التي يخصها (تظهر في صفحاتها وصفحات المستويات الأعلى)</span>
                @foreach($linkable as $row)
                    <label style="--entity-depth: {{ min($row['depth'], 6) }}"><input type="checkbox" name="subjects[]" value="{{ $row['entity']->id }}" @checked(in_array($row['entity']->id, $selectedSubjects, true))> {{ $row['entity']->name_ar }} <small class="text-muted">({{ $row['entity']->type_label }})</small></label>
                @endforeach
            </div>
            <div class="content-entity-choices">
                <span class="fw-bold">موجّه إلى (يظهر في صفحة الكيان وكل فروعه) — اختياري</span>
                @foreach($linkable as $row)
                    <label style="--entity-depth: {{ min($row['depth'], 6) }}"><input type="checkbox" name="audiences[]" value="{{ $row['entity']->id }}" @checked(in_array($row['entity']->id, $selectedAudiences, true))> {{ $row['entity']->name_ar }}</label>
                @endforeach
            </div>
            @error('subjects')<span class="field-error">{{ $message }}</span>@enderror
            <label>جمهور العرض<select class="form-select" name="audience">@foreach(\App\Models\EntityNarrative::AUDIENCES as $key => $label)<option value="{{ $key }}" @selected($v('audience', 'public') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>حالة التحقق<select class="form-select" name="verification_status">@foreach(\App\Models\EntityNarrative::VERIFICATION_STATUSES as $key => $label)<option value="{{ $key }}" @selected($v('verification_status', 'pending_review') === $key) @disabled(! $canPublish && in_array($key, ['verified', 'sourced'], true) && $record->verification_status !== $key)>{{ $label }}</option>@endforeach</select>@error('verification_status')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label>حالة النشر<select class="form-select" name="publication_status">@foreach(\App\Models\EntityNarrative::PUBLICATION_STATUSES as $key => $label)<option value="{{ $key }}" @selected($v('publication_status', 'draft') === $key) @disabled(! $canPublish && ! in_array($key, ['draft', 'submitted'], true))>{{ $label }}</option>@endforeach</select>@error('publication_status')<span class="field-error">{{ $message }}</span>@enderror</label>
        </fieldset>
        <button class="btn btn-primary" @disabled($locked)>{{ $record->exists ? 'حفظ التعديلات' : 'حفظ السجل' }}</button>
    </form>
</section>

@if($record->exists)
<section class="management-panel" aria-labelledby="review-heading">
    <h2 class="h5" id="review-heading">المراجعة والنشر</h2>
    @if($record->publication_status === 'draft')
        <form method="post" action="{{ route('management.entities.content.submit', [$family->slug, $entity->id, $module, $record->id]) }}">@csrf<button class="btn btn-outline-primary" data-submit-review>إرسال للمراجعة</button></form>
    @endif
    @if($canPublish)
        <form method="post" action="{{ route('management.entities.content.review', [$family->slug, $entity->id, $module, $record->id]) }}" class="management-grid mt-3" data-review-form>
            @csrf
            <label>ملاحظة المراجعة (مطلوبة عند الإعادة إلى المسودة)<textarea class="form-control" name="review_note" rows="2"></textarea>@error('review_note')<span class="field-error">{{ $message }}</span>@enderror</label>
            <div class="d-flex flex-wrap gap-2">
                @if(in_array($record->publication_status, ['draft', 'submitted'], true))<button class="btn btn-success" name="decision" value="publish">نشر</button>@endif
                @if($record->publication_status === 'submitted')<button class="btn btn-outline-warning" name="decision" value="decline">إعادة إلى المسودة</button>@endif
                @if($record->publication_status !== 'archived')<button class="btn btn-outline-secondary" name="decision" value="archive">أرشفة</button>@else<button class="btn btn-outline-primary" name="decision" value="restore">استعادة كمسودة</button>@endif
            </div>
            @error('residence_consent')<span class="field-error">{{ $message }}</span>@enderror
        </form>
    @endif
</section>

<section class="management-panel" aria-labelledby="citations-heading">
    <h2 class="h5" id="citations-heading">المصادر والاستشهادات</h2>
    <p class="small text-muted">تظهر للعامة الاستشهادات «الموثقة» أو «المسندة» فقط.</p>
    <div class="management-list">
        @forelse($citations as $citation)
            <article data-citation="{{ $citation->id }}">
                <div><strong>{{ $citation->source?->title }}</strong><small>{{ $citation->locator }} · {{ \App\Models\EntityNarrative::VERIFICATION_STATUSES[$citation->verification_status] ?? $citation->verification_status }}</small></div>
                @if($canPublish)
                    <form method="post" action="{{ route('management.entities.content.citations.update', [$family->slug, $entity->id, $module, $record->id, $citation->id]) }}" class="d-flex gap-1">@csrf @method('PUT')<select class="form-select form-select-sm" name="verification_status" aria-label="حالة الاستشهاد">@foreach(\App\Models\EntityNarrative::VERIFICATION_STATUSES as $key => $label)<option value="{{ $key }}" @selected($citation->verification_status === $key)>{{ $label }}</option>@endforeach</select><button class="btn btn-sm btn-outline-primary">حفظ</button></form>
                @endif
                @if($canPublish || ! in_array($citation->verification_status, \App\Models\ContentCitation::PUBLIC_STATUSES, true))
                    <form method="post" action="{{ route('management.entities.content.citations.destroy', [$family->slug, $entity->id, $module, $record->id, $citation->id]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">إزالة</button></form>
                @endif
            </article>
        @empty
            <p class="text-muted mb-0">لا توجد استشهادات بعد.</p>
        @endforelse
    </div>
    <form method="post" action="{{ route('management.entities.content.citations.store', [$family->slug, $entity->id, $module, $record->id]) }}" class="management-grid mt-3" data-citation-form>
        @csrf
        <label>المصدر<select class="form-select" name="source_id" required><option value="">اختر مصدرًا من مكتبة العائلة</option>@foreach($sources as $source)<option value="{{ $source->id }}">{{ $source->title }}</option>@endforeach</select>@error('source_id')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>الموضع (صفحة، فقرة…)<input class="form-control" name="locator" maxlength="255"></label>
        <label>رابط (اختياري)<input class="form-control" name="url" type="url" dir="ltr" maxlength="2000">@error('url')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>ملاحظات<textarea class="form-control" name="notes" rows="2"></textarea></label>
        <label>حالة الاستشهاد<select class="form-select" name="verification_status">@foreach(\App\Models\EntityNarrative::VERIFICATION_STATUSES as $key => $label)<option value="{{ $key }}" @disabled(! $canPublish && in_array($key, ['verified', 'sourced'], true))>{{ $label }}</option>@endforeach</select></label>
        <button class="btn btn-outline-primary">إضافة استشهاد</button>
    </form>
    @if($sources->isEmpty())<p class="small text-muted mt-2">لا توجد مصادر بعد؛ أضفها من <a href="{{ route('management.sources.index', $family->slug) }}">المصادر والاستشهادات</a>.</p>@endif
</section>

<section class="management-panel" aria-labelledby="history-heading">
    <h2 class="h5" id="history-heading">سجل المراجعة</h2>
    <ul class="small mb-0" data-review-history>
        @forelse($history as $log)
            <li>{{ $log->created_at?->format('Y-m-d H:i') }} · {{ $log->user?->name ?? 'النظام' }} · {{ $actionLabels[$log->action] ?? $log->action }}@if(class_basename($log->auditable_type) === 'ContentCitation') (استشهاد)@endif @if($log->after['review_note'] ?? null): {{ $log->after['review_note'] }}@endif <a href="{{ route('management.audit.show', $log->id) }}">التفاصيل</a></li>
        @empty
            <li class="text-muted">لا توجد أحداث بعد.</li>
        @endforelse
    </ul>
</section>
@endif
@endsection
