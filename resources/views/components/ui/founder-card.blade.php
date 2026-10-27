@props(['founder', 'family', 'tree' => null, 'branch' => null, 'classification' => null, 'treeUrl' => null, 'private' => false])
{{-- Founder from an approved branch association only; the person is already filtered by the caller's visibility rules. --}}
<article {{ $attributes->merge(['class' => 'j-founder']) }} data-founder="{{ $founder->id }}">
    <span class="j-founder-mark" aria-hidden="true">★</span>
    <div class="min-w-0">
        <span class="j-founder-class">{{ $classification ?? 'المؤسس' }}@if($branch) — {{ $branch->name_ar }}@endif</span>
        <h3>{{ $founder->full_name_ar }}</h3>
        <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
            <x-ui.verification :status="$founder->verification_status" />
            @if($branch)<x-ui.verification :status="$branch->verification_status" :label="'توثيق الفرع: '.(['verified' => 'موثق', 'sourced' => 'مسند'][$branch->verification_status] ?? 'قيد المراجعة')" />@endif
        </div>
        @if($branch?->description)<p class="mb-2 mt-2 small">{{ \Illuminate\Support\Str::limit($branch->description, 260) }}</p>@endif
        <div class="d-flex flex-wrap gap-3 mt-2">
            <a href="{{ route('genealogy.person', [$family->slug, $founder->id, 'tree' => $tree?->slug, 'private' => $private ? 1 : null]) }}"><i class="bi bi-person"></i> صفحة المؤسس</a>
            @if($treeUrl)<a href="{{ $treeUrl }}"><i class="bi bi-diagram-3"></i> في الشجرة</a>@endif
        </div>
    </div>
</article>
