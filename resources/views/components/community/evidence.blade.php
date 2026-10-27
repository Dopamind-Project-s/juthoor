@props(['record', 'entity' => null, 'alternative' => false])
@php
    $shared = $record->entityLinks->pluck('entity')->filter()->unique('id')->reject(fn ($linked) => $entity && $linked->id === $entity->id);
@endphp
<div class="content-evidence j-evidence">
    <x-ui.verification :status="$record->verification_status" :label="$record->verification_label" :alternative="$alternative" />
    @if($alternative)<span class="small text-muted">رواية تاريخية دون تحقق نهائي</span>@endif
    @if($record->audience === 'members')<span class="j-pill">لأعضاء العائلة</span>@endif
    @if($record->reviewed_at)<span class="small text-muted">روجع {{ $record->reviewed_at->format('Y-m-d') }}</span>@endif
    @if($shared->isNotEmpty())<span class="small text-muted" data-shared-with>يخص أيضًا: {{ $shared->pluck('name_ar')->implode('، ') }}</span>@endif
    @if($record->citations->isNotEmpty())
        <ul class="content-citations j-sources">
            @foreach($record->citations as $citation)
                <li><i class="bi bi-journal-text" aria-hidden="true"></i><span>{{ $citation->source?->title }}@if($citation->source?->source_type)<span class="text-muted"> ({{ $citation->source->source_type }})</span>@endif @if($citation->locator) — {{ $citation->locator }}@endif @if($citation->url)<a href="{{ $citation->url }}" rel="noopener nofollow" target="_blank">الرابط</a>@endif</span></li>
            @endforeach
        </ul>
    @else
        <p class="small text-muted mb-0 w-100">لا توجد مصادر معتمدة معروضة لهذه المعلومة.</p>
    @endif
</div>
