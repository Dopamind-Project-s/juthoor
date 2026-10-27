@props(['status', 'label' => null, 'alternative' => false])
{{-- One verification vocabulary for genealogy and heritage records; colour plus a glyph and text, never colour alone. --}}
@php
    // An alternative historical account keeps its recorded status text; only its marker differs (unless verified).
    $kind = match (true) {
        $status === 'verified' => 'verified',
        $alternative && $status !== 'unresolved' => 'historical',
        $status === 'sourced' => 'sourced',
        in_array($status, ['unresolved', 'disputed'], true) => 'unresolved',
        default => 'pending',
    };
    $text = $label ?? match ($kind) {
        'historical' => 'رواية تاريخية دون تحقق نهائي',
        'verified' => 'موثق',
        'sourced' => 'مسند إلى مصدر',
        'unresolved' => 'محل خلاف',
        default => 'قيد المراجعة',
    };
@endphp
<span {{ $attributes->merge(['class' => 'j-verify is-'.$kind]) }} data-verification="{{ $status }}">{{ $text }}</span>
