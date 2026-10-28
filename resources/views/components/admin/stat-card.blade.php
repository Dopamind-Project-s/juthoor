@props(['label', 'value', 'icon' => 'bar-chart'])
<div {{ $attributes->class(['dashboard-stat', 'admin-stat']) }}><span class="admin-stat-icon" aria-hidden="true"><i class="bi bi-{{ $icon }}"></i></span><small>{{ $label }}</small><strong>{{ number_format($value) }}</strong></div>
