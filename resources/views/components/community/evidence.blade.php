@props(['record', 'entity' => null])
@php
    $shared = $record->entityLinks->pluck('entity')->filter()->unique('id')->reject(fn ($linked) => $entity && $linked->id === $entity->id);
    $badge = ['verified' => 'text-bg-success', 'sourced' => 'text-bg-info', 'unresolved' => 'text-bg-warning'][$record->verification_status] ?? 'text-bg-light';
@endphp
<div class="content-evidence">
    <span class="badge {{ $badge }}" data-verification="{{ $record->verification_status }}">{{ $record->verification_label }}</span>
    @if($record->audience === 'members')<span class="badge text-bg-secondary">لأعضاء العائلة</span>@endif
    @if($shared->isNotEmpty())<span class="small text-muted" data-shared-with>يخص أيضًا: {{ $shared->pluck('name_ar')->implode('، ') }}</span>@endif
    @if($record->citations->isNotEmpty())
        <ul class="content-citations">
            @foreach($record->citations as $citation)
                <li><i class="bi bi-journal-text" aria-hidden="true"></i> {{ $citation->source?->title }}@if($citation->locator) — {{ $citation->locator }}@endif @if($citation->url)<a href="{{ $citation->url }}" rel="noopener nofollow" target="_blank">الرابط</a>@endif</li>
            @endforeach
        </ul>
    @else
        <p class="small text-muted mb-0">لا توجد مصادر معتمدة معروضة لهذه المعلومة.</p>
    @endif
</div>
