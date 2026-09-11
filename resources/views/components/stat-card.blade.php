@props(['icon','value','label'])
<div class="stat-card d-flex align-items-center gap-3"><span class="stat-icon"><i class="bi bi-{{ $icon }}"></i></span><div><div class="stat-value">{{ $value }}</div><div class="small text-muted">{{ $label }}</div></div></div>
