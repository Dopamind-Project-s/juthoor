@extends('layouts.management')
@php
    $moduleInfo = \App\Services\Community\CommunityContentModules::MODULES[$module];
    $v = fn (string $field, $default = null) => old($field, $record->{$field} ?? $default);
    $selectedSubjects = array_map('intval', old('subjects', $subjects));
    $selectedAudiences = array_map('intval', old('audiences', $audiences));
    $locked = $record->exists && ! $canPublish && in_array($record->publication_status, ['published', 'archived'], true);
    $actionLabels = ['created' => 'إنشاء', 'updated' => 'تعديل', 'submitted' => 'إرسال للمراجعة', 'review_publish' => 'نشر', 'review_decline' => 'إعادة إلى المسودة', 'review_archive' => 'أرشفة', 'review_restore' => 'استعادة من الأرشيف', 'deleted' => 'حذف', 'file_replaced' => 'استبدال الملف'];
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
    <form method="post" action="{{ $record->exists ? route('management.entities.content.update', [$family->slug, $entity->id, $module, $record->id]) : route('management.entities.content.store', [$family->slug, $entity->id, $module]) }}" class="management-grid entity-form" data-content-form enctype="multipart/form-data">
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
            @if(method_exists($record, 'linkedPeople'))
                <div class="content-entity-choices" data-linked-people>
                    <span class="fw-bold">أشخاص من الشجرة يشير إليهم السجل (اختياري)</span>
                    @foreach($linkedPeople as $person)<label><input type="checkbox" name="people[]" value="{{ $person->id }}" checked> {{ $person->display_name }}</label>@endforeach
                    <select class="form-select" name="people[]" data-remote-select data-remote-url="{{ route('management.lookups.people', [$family->slug, 'tree' => $tree->slug]) }}" aria-label="إضافة شخص"><option value="">إضافة شخص</option></select>
                    <small class="text-muted">يظهر الشخص للزوار فقط إذا كان منشورًا في الشجرة؛ احرص على موافقة المذكورين، خاصة الأطفال.</small>
                    @error('people.*')<span class="field-error">{{ $message }}</span>@enderror
                </div>
            @endif
            <label>جمهور العرض<select class="form-select" name="audience">@foreach(\App\Models\EntityNarrative::AUDIENCES as $key => $label)<option value="{{ $key }}" @selected($v('audience', 'public') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>حالة التحقق<select class="form-select" name="verification_status">@foreach(\App\Models\EntityNarrative::VERIFICATION_STATUSES as $key => $label)<option value="{{ $key }}" @selected($v('verification_status', 'pending_review') === $key) @disabled(! $canPublish && in_array($key, ['verified', 'sourced'], true) && $record->verification_status !== $key)>{{ $label }}</option>@endforeach</select>@error('verification_status')<span class="field-error">{{ $message }}</span>@enderror</label>
            @if($record instanceof \App\Models\NewsPost)<label class="flex-row align-items-center gap-2"><input type="checkbox" name="sensitive_confirmation" value="1"> للنعي والعزاء: تحققتُ من الخبر من جهة موثوقة ومن موافقة ذوي المتوفى (مدير العائلة فقط)</label>@error('sensitive_confirmation')<span class="field-error">{{ $message }}</span>@enderror @error('family_consent')<span class="field-error">{{ $message }}</span>@enderror @endif
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
                @if($record instanceof \App\Models\NewsPost && $record->isSensitive() && ! $record->sensitive_confirmed_at)<label class="flex-row align-items-center gap-2 w-100"><input type="checkbox" name="sensitive_confirmation" value="1"> تحققتُ من الخبر ومن موافقة ذوي المتوفى</label>@endif
                @if(in_array($record->publication_status, ['draft', 'submitted'], true))<button class="btn btn-success" name="decision" value="publish">نشر</button>@endif
                @if($record->publication_status === 'submitted')<button class="btn btn-outline-warning" name="decision" value="decline">إعادة إلى المسودة</button>@endif
                @if($record->publication_status !== 'archived')<button class="btn btn-outline-secondary" name="decision" value="archive">أرشفة</button>@else<button class="btn btn-outline-primary" name="decision" value="restore">استعادة كمسودة</button>@endif
            </div>
            @error('residence_consent')<span class="field-error">{{ $message }}</span>@enderror
            @error('rights_status')<span class="field-error">{{ $message }}</span>@enderror
            @error('alt_text')<span class="field-error">{{ $message }}</span>@enderror
            @error('sensitive_confirmation')<span class="field-error">{{ $message }}</span>@enderror
            @error('family_consent')<span class="field-error">{{ $message }}</span>@enderror
        </form>
    @endif
    @if($record instanceof \App\Models\CommunityEvent)
        <form method="post" action="{{ route('management.entities.content.announce', [$family->slug, $entity->id, $module, $record->id]) }}" class="mt-3">@csrf<button class="btn btn-outline-primary" data-announce>إنشاء إعلان مرتبط بهذه المناسبة</button></form>
        @foreach($record->announcements as $announcement)<p class="small mb-0">إعلان مرتبط: <a href="{{ route('management.entities.content.edit', [$family->slug, $announcement->owner_entity_id, 'news', $announcement->id]) }}">{{ $announcement->title }}</a> ({{ $announcement->status_label }})</p>@endforeach
    @endif
</section>

@if($record instanceof \App\Models\NewsPost || $record instanceof \App\Models\CommunityEvent)
<section class="management-panel" aria-labelledby="notices-heading" data-notices-panel>
    <h2 class="h5" id="notices-heading">تحديثات وتغييرات {{ $record instanceof \App\Models\CommunityEvent ? 'الموعد' : 'الإعلان' }}</h2>
    <p class="small text-muted">تظهر للقراء مع السجل، وتُحفظ القيم السابقة. بعد النشر تتطلب صلاحية النشر.</p>
    <ul class="small" data-notices>
        @forelse($notices as $notice)<li>{{ $notice->created_at?->setTimezone(config('community.timezone'))->format('Y-m-d H:i') }} · {{ \App\Models\ContentNotice::KINDS[$notice->kind] ?? $notice->kind }} · {{ $notice->message }} — {{ $notice->author?->name }}</li>@empty<li class="text-muted">لا توجد تحديثات.</li>@endforelse
    </ul>
    <form method="post" action="{{ route('management.entities.content.notices.store', [$family->slug, $entity->id, $module, $record->id]) }}" class="management-grid" data-notice-form>
        @csrf
        <label>النوع<select class="form-select" name="kind">
            <option value="update">تحديث</option><option value="correction">تصحيح</option>
            @if($record instanceof \App\Models\CommunityEvent)
                @foreach(\App\Services\Community\CommunityPublishingService::EVENT_CHANGES as $kind)<option value="{{ $kind }}">{{ \App\Models\ContentNotice::KINDS[$kind] }}</option>@endforeach
            @elseif(! $record->cancelled_at)<option value="cancellation">إلغاء الإعلان</option>@endif
        </select>@error('kind')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>النص الظاهر للقراء<textarea class="form-control" name="message" rows="2" required maxlength="2000"></textarea>@error('message')<span class="field-error">{{ $message }}</span>@enderror</label>
        @if($record instanceof \App\Models\CommunityEvent)
            <label>الموعد الجديد (لتغيير الموعد أو التأجيل، بتوقيت {{ $record->timezone }})<input class="form-control" type="datetime-local" name="starts_at"></label>
            <label>الانتهاء الجديد<input class="form-control" type="datetime-local" name="ends_at"></label>
            <label>المكان الجديد (لتغيير المكان)<input class="form-control" name="location_name" placeholder="اسم المكان"></label>
            <label>العنوان العام الجديد<input class="form-control" name="public_address"></label>
            @error('starts_at')<span class="field-error">{{ $message }}</span>@enderror @error('ends_at')<span class="field-error">{{ $message }}</span>@enderror
        @endif
        <button class="btn btn-outline-primary">نشر التحديث</button>
    </form>
</section>
@endif

@if($attachments !== null)
<section class="management-panel" aria-labelledby="attachments-heading" data-attachments>
    <h2 class="h5" id="attachments-heading">الملفات المرفقة</h2>
    <p class="small text-muted">تُرفق الملفات من مكتبة وسائط العائلة دون نسخها. يظهر الملف للزوار فقط إذا كان هو نفسه منشورًا لهم.</p>
    <div class="management-list">
        @forelse($attachments as $attachment)
            <article data-attachment="{{ $attachment->id }}">
                <div><strong>{{ $attachment->media?->title }}</strong><small>{{ $attachment->media?->status_label }} · {{ \App\Models\MediaItem::AUDIENCES[$attachment->media?->audience] ?? '' }}@if($attachment->caption) · {{ $attachment->caption }}@endif</small></div>
                <form method="post" action="{{ route('management.entities.content.media.destroy', [$family->slug, $entity->id, $module, $record->id, $attachment->id]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">إزالة من السجل</button></form>
            </article>
        @empty
            <p class="text-muted mb-0">لا توجد ملفات مرفقة.</p>
        @endforelse
    </div>
    <form method="post" action="{{ route('management.entities.content.media.store', [$family->slug, $entity->id, $module, $record->id]) }}" class="management-grid mt-3" data-attach-form>
        @csrf
        <label>ملف من المكتبة<select class="form-select" name="media_item_id" required><option value="">اختر ملفًا</option>@foreach($library as $item)<option value="{{ $item->id }}">{{ $item->title }} — {{ \App\Models\MediaItem::CATEGORIES[$item->category] ?? '' }} · {{ $item->status_label }}</option>@endforeach</select>@error('media_item_id')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>تعليق (اختياري)<input class="form-control" name="caption" maxlength="500"></label>
        <button class="btn btn-outline-primary">إرفاق</button>
    </form>
    <p class="small mt-2 mb-0"><a href="{{ route('management.entities.content.create', [$family->slug, $entity->id, 'media']) }}">رفع ملف جديد إلى المكتبة</a></p>
</section>
@endif

@if($module === 'media')
<section class="management-panel" aria-labelledby="usage-heading" data-media-usage>
    <h2 class="h5" id="usage-heading">استخدامات الملف</h2>
    <ul class="small">
        @foreach($usage as $attachment)<li>{{ $attachment->attachable?->content_title }} ({{ class_basename($attachment->attachable_type) }})</li>@endforeach
        @foreach($coverOf as $covered)<li>صورة غلاف: {{ $covered->name_ar }}</li>@endforeach
        @if($usage->isEmpty() && $coverOf->isEmpty())<li class="text-muted">غير مستخدم في سجلات أخرى.</li>@endif
    </ul>
    @if($usage->isEmpty() && $coverOf->isEmpty() && $record->publication_status !== 'published')
        <form method="post" action="{{ route('management.entities.content.destroy', [$family->slug, $entity->id, $module, $record->id]) }}" onsubmit="return confirm('حذف الملف نهائيًا؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" data-delete-media>حذف الملف نهائيًا</button></form>
    @else
        <p class="small text-muted mb-0">لا يُحذف ملف منشور أو مستخدم؛ يمكن أرشفته.</p>
    @endif
    @error('media')<span class="field-error">{{ $message }}</span>@enderror
</section>
@endif

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
