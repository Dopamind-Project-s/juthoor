@props(['name','years'=>'غير محدد','status'=>'موثق'])
<article class="product-card card-body text-center"><span class="node-avatar mx-auto mb-2"><i class="bi bi-person-fill"></i></span><h3 class="h6 fw-bold mb-1">{{ $name }}</h3><p class="small text-muted mb-2">{{ $years }}</p><span class="status-badge"><i class="bi bi-check-circle"></i> {{ $status }}</span></article>
