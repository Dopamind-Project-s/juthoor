@extends('layouts.management')
@section('page-heading', $entity->exists ? 'إدارة كيان' : 'كيان جديد')
@section('title', $entity->exists ? $entity->name_ar : 'كيان جديد')
@php
    $value = fn (string $field, $default = null) => old($field, $entity->{$field} ?? $default);
@endphp
@section('content')
<section class="management-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <span class="eyebrow"><a href="{{ route('management.entities.index', $family->slug) }}">صفحات الكيانات</a></span>
            <h1 class="h4">{{ $entity->exists ? $entity->name_ar : 'إضافة كيان' }}</h1>
            @if($entity->exists)<p class="text-muted mb-0">{{ $entity->type_label }} · {{ $entity->status_label }}@if($isPublic) · ظاهرة للعامة@endif · اكتمال التعريف {{ $completeness['done'] }} من {{ $completeness['total'] }}@if($entity->reviewed_at) · آخر مراجعة {{ $entity->reviewed_at->format('Y-m-d') }}@endif</p>@endif
        </div>
        @if($entity->exists)
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="{{ route('community.entities.show', [$family->slug, $entity->slug, 'preview' => $isPublic ? null : 1]) }}"><i class="bi bi-box-arrow-up-left"></i> {{ $isPublic ? 'الصفحة العامة' : 'معاينة الصفحة' }}</a>
                <a class="btn btn-outline-primary" href="{{ route('management.entities.create', [$family->slug, 'parent' => $entity->id]) }}"><i class="bi bi-plus-lg"></i> كيان تابع</a>
            </div>
        @endif
    </div>
    @error('entity')<div class="alert alert-danger mt-3" role="alert">{{ $message }}</div>@enderror
    @if(! $canPublish)<p class="alert alert-info mt-3 mb-0">يمكنك حفظ الكيان مسودة. النشر والأرشفة وتأكيد المراجعة وتعديل صفحة منشورة من صلاحية مدير العائلة أو مدير الكيان.</p>@endif
</section>

<section class="management-panel">
    <form method="post" action="{{ $entity->exists ? route('management.entities.update', [$family->slug, $entity->id]) : route('management.entities.store', $family->slug) }}" class="management-grid entity-form" data-entity-form>
        @csrf
        @if($entity->exists) @method('PUT') @endif
        <label>الاسم بالعربية<input class="form-control" name="name_ar" value="{{ $value('name_ar') }}" required maxlength="255">@error('name_ar')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>الاسم بالإنجليزية (اختياري)<input class="form-control" name="name_en" value="{{ $value('name_en') }}" maxlength="255" dir="ltr">@error('name_en')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>الرابط المختصر<input class="form-control" name="slug" value="{{ $value('slug') }}" required maxlength="120" dir="ltr" aria-describedby="slug-help"><small id="slug-help" class="text-muted fw-normal">حروف وأرقام وشرطات، فريد داخل العائلة.</small>@error('slug')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>المستوى<select class="form-select" name="entity_type" required>@foreach(\App\Models\CommunityEntity::RANKS as $type => $rank)<option value="{{ $type }}" @selected($value('entity_type', 'clan') === $type)>{{ \App\Models\Branch::TYPES[$type] }}</option>@endforeach</select>@error('entity_type')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>الكيان الأعلى<select class="form-select" name="parent_id">
            @if($canCreateRoot || ($entity->exists && $entity->parent_id === null))<option value="">دون كيان أعلى (أعلى الهرم)</option>@endif
            @foreach($parents as $row)<option value="{{ $row['entity']->id }}" @selected((string) $value('parent_id') === (string) $row['entity']->id)>{{ str_repeat('— ', $row['depth']) }}{{ $row['entity']->name_ar }} ({{ $row['entity']->type_label }})</option>@endforeach
        </select>@error('parent_id')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>الترتيب<input class="form-control" type="number" min="0" max="100000" name="sort_order" value="{{ $value('sort_order', 0) }}">@error('sort_order')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>أسماء أخرى<textarea class="form-control" name="alternate_names" rows="2" aria-describedby="names-help">{{ old('alternate_names', implode("\n", $entity->alternate_names ?? [])) }}</textarea><small id="names-help" class="text-muted fw-normal">اسم في كل سطر أو مفصولة بفواصل.</small>@error('alternate_names')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>نبذة تعريفية<textarea class="form-control" name="summary" rows="2" maxlength="500">{{ $value('summary') }}</textarea>@error('summary')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>التعريف المفصل<textarea class="form-control" name="description" rows="6" maxlength="10000">{{ $value('description') }}</textarea>@error('description')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>الشجرة المرتبطة (اختياري)<select class="form-select" name="family_tree_id"><option value="">دون ربط</option>@foreach($trees as $item)<option value="{{ $item->id }}" @selected((string) $value('family_tree_id') === (string) $item->id)>{{ $item->name_ar }}</option>@endforeach</select>@error('family_tree_id')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>الفرع النسبي المرتبط (اختياري)<select class="form-select" name="branch_id" aria-describedby="branch-help"><option value="">دون ربط — كيان اجتماعي</option>@foreach($branches as $item)<option value="{{ $item->id }}" @selected((string) $value('branch_id') === (string) $item->id)>{{ $item->name_ar }} — {{ $item->type_label }} · المؤسس: {{ $item->rootPerson?->full_name_ar ?? 'غير محدد' }} · {{ $item->familyTree?->name_ar }}</option>@endforeach</select><small id="branch-help" class="text-muted fw-normal">يُعرض مؤسس الفرع وشجرته في الصفحة من السجلات الحالية دون نسخ. لكل فرع صفحة كيان واحدة.</small>@error('branch_id')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>حالة النشر<select class="form-select" name="publication_status">@foreach(\App\Models\CommunityEntity::STATUSES as $status => $label)<option value="{{ $status }}" @selected($value('publication_status', 'draft') === $status) @disabled(! $canPublish && $status !== \App\Models\CommunityEntity::STATUS_DRAFT)>{{ $label }}</option>@endforeach</select>@error('publication_status')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label>صورة الغلاف (اختياري)<select class="form-select" name="cover_media_id"><option value="">دون صورة غلاف</option>@foreach($coverImages as $image)<option value="{{ $image->id }}" @selected((string) $value('cover_media_id') === (string) $image->id)>{{ $image->title }} — {{ $image->status_label }} · {{ \App\Models\MediaItem::AUDIENCES[$image->audience] ?? $image->audience }}</option>@endforeach</select><small class="text-muted fw-normal">تظهر في الصفحة العامة فقط إذا كانت الصورة منشورة للعامة. أضف الصور من «مكتبة الوسائط».</small>@error('cover_media_id')<span class="field-error">{{ $message }}</span>@enderror</label>
        <fieldset class="content-governance">
            <legend class="h6">التعداد التقديري من مصدر مستقل (اختياري)</legend>
            <label>العدد التقديري<input class="form-control" type="number" min="1" name="population_estimate" value="{{ $value('population_estimate') }}">@error('population_estimate')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label>سنة التقدير<input class="form-control" type="number" name="population_estimate_year" value="{{ $value('population_estimate_year') }}">@error('population_estimate_year')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label>المصدر<select class="form-select" name="population_estimate_source_id"><option value="">دون مصدر</option>@foreach($estimateSources as $source)<option value="{{ $source->id }}" @selected((string) $value('population_estimate_source_id') === (string) $source->id)>{{ $source->title }}</option>@endforeach</select>@error('population_estimate_source_id')<span class="field-error">{{ $message }}</span>@enderror<small class="text-muted fw-normal">لا يُعرض عدد السجلات بوصفه تعدادًا؛ هذا الحقل لتقدير موثق من مصدر خارجي فقط.</small></label>
        </fieldset>
        @if($canPublish)<label class="flex-row align-items-center"><input type="checkbox" name="mark_reviewed" value="1"> تمت مراجعة معلومات الصفحة الآن@error('mark_reviewed')<span class="field-error">{{ $message }}</span>@enderror</label>@endif
        <button class="btn btn-primary">{{ $entity->exists ? 'حفظ التعديلات' : 'إضافة الكيان' }}</button>
    </form>
</section>

@if($entity->exists)
<section class="management-panel" aria-labelledby="entity-content" data-entity-content-panel>
    <h2 class="h5" id="entity-content">محتوى الصفحة</h2>
    <p class="text-muted small">التاريخ والمناطق والدواوين والشخصيات سجلات يملكها هذا الكيان ويمكن ربطها بكيانات أخرى دون نسخ. الإحصاءات تُحسب من الشجرة تلقائيًا، والصلة النسبية من حقلي الشجرة والفرع أعلاه.</p>
    <div class="management-list">
        @foreach(\App\Services\Community\CommunityContentModules::MODULES as $key => $info)
            <article data-content-module="{{ $key }}">
                <div><strong><i class="bi bi-{{ $info['icon'] }}"></i> {{ $info['label'] }}</strong><small>@forelse($contentCounts[$key] as $status => $total){{ \App\Models\EntityNarrative::PUBLICATION_STATUSES[$status] ?? $status }}: {{ $total }}@if(! $loop->last) · @endif @empty لا سجلات بعد @endforelse</small></div>
                <a class="btn btn-sm btn-primary" href="{{ route('management.entities.content.index', [$family->slug, $entity->id, $key]) }}">إدارة</a>
            </article>
        @endforeach
    </div>
</section>

<section class="management-panel" aria-labelledby="entity-managers">
    <h2 class="h5" id="entity-managers">مسؤولو الكيان</h2>
    <p class="text-muted small">يدير مسؤول الكيان هذه الصفحة والكيانات التابعة لها فقط، ولا يملك صلاحية على المستويات الأعلى أو الكيانات المجاورة. ظهور محتوى في صفحة الكيان لا يمنحه صلاحية تعديله.</p>
    <div class="management-list">
        @forelse($managers as $manager)
            <article data-entity-manager="{{ $manager->user?->username }}">
                <div><strong>{{ $manager->user?->name }}</strong><small>{{ \App\Models\CommunityEntityManager::ROLES[$manager->role] ?? $manager->role }}</small></div>
                @if($members->isNotEmpty())<form method="post" action="{{ route('management.entities.managers.destroy', [$family->slug, $entity->id, $manager->id]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">إزالة</button></form>@endif
            </article>
        @empty
            <p class="text-muted mb-0">لا يوجد مسؤولون خاصون بهذا الكيان؛ يديره محررو العائلة ومديروها.</p>
        @endforelse
    </div>
    @if($members->isNotEmpty())
        <form method="post" action="{{ route('management.entities.managers.store', [$family->slug, $entity->id]) }}" class="management-grid mt-3">
            @csrf
            <label>عضو العائلة<select class="form-select" name="user_id" required><option value="">اختر عضوًا</option>@foreach($members as $member)<option value="{{ $member->id }}">{{ $member->name }} ({{ $member->username }})</option>@endforeach</select>@error('user_id')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label>الدور<select class="form-select" name="role">@foreach(\App\Models\CommunityEntityManager::ROLES as $role => $label)<option value="{{ $role }}">{{ $label }}</option>@endforeach</select></label>
            <button class="btn btn-outline-primary">حفظ النطاق</button>
        </form>
    @endif
</section>
@endif
@endsection
