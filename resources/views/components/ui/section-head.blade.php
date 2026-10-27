@props(['kicker' => null, 'title', 'id' => null, 'lead' => null, 'level' => 2])
<div class="j-section-head">
    <div>@if($kicker)<span class="j-kicker">{{ $kicker }}</span>@endif
        <h{{ $level }} class="j-heading" @if($id) id="{{ $id }}" @endif>{{ $title }}</h{{ $level }}>
        @if($lead)<p class="j-lead mb-0">{{ $lead }}</p>@endif</div>
    @if(trim($slot))<div class="d-flex flex-wrap gap-2">{{ $slot }}</div>@endif
</div>
