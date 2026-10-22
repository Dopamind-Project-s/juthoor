@php
    $verificationLabels = ['pending_review' => 'قيد المراجعة', 'sourced' => 'مسند', 'verified' => 'موثق', 'unresolved' => 'غير محسوم'];
    $publicationRestricted = ! request()->routeIs('management.changes.*') && auth()->user()?->cannot('publish', $family);
@endphp
@foreach($statuses ?? array_keys($verificationLabels) as $value)<option value="{{ $value }}" @selected($selected === $value) @disabled($publicationRestricted && in_array($value, ['verified', 'sourced'], true) && $selected !== $value)>{{ $verificationLabels[$value] }}{{ $publicationRestricted && in_array($value, ['verified', 'sourced'], true) ? ' — يعتمده مدير العائلة' : '' }}</option>@endforeach
