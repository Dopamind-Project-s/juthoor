@props(['title','type','date'])
<article class="product-card card-body source-card"><span class="source-icon"><i class="bi bi-file-earmark-text"></i></span><div><h3 class="h6 fw-bold">{{ $title }}</h3><p class="small text-muted mb-1">{{ $type }}</p><small class="text-muted"><i class="bi bi-calendar3"></i> {{ $date }}</small></div></article>
