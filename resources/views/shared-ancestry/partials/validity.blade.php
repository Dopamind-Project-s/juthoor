@php
    $validityLabels = [
        'valid' => ['صالح: تحقق كامل ولم تتغير بياناته بعده', 'text-bg-success'],
        'stale' => ['معتمد بانتظار إعادة التحقق؛ لا يُعرض موثقًا حتى يُعاد', 'text-bg-warning'],
        'needs_review' => ['غير صالح: تغيرت البيانات ويحتاج تأكيدًا جديدًا', 'text-bg-danger'],
        'pending_review' => ['قيد المراجعة', 'text-bg-secondary'],
        'rejected' => ['مرفوض', 'text-bg-light'],
        'disputed' => ['مختلف عليه', 'text-bg-light'],
    ];
    [$validityLabel, $validityClass] = $validityLabels[$state] ?? [$state, 'text-bg-light'];
@endphp
<div class="d-flex flex-wrap gap-2 align-items-center" data-link-validity="{{ $state }}">
    <span class="badge {{ $validityClass }}">{{ $validityLabel }}</span>
    @if($link->validated_at)<small class="text-muted">آخر تحقق كامل: {{ $link->validated_at->format('Y-m-d H:i') }}</small>@endif
    @if($link->status === 'approved')
        <form method="post" action="{{ route('cross-links.revalidate',$link) }}">@csrf<button class="btn btn-sm btn-outline-primary">إعادة التحقق الآن</button></form>
    @endif
</div>
