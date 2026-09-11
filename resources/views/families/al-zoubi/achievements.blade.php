@extends('layouts.family')
@section('title','إنجازات العشيرة وأبنائها')
@section('content')
<x-family-page-header title="إنجازات العشيرة وأبنائها" icon="award" description="مساحة مخصصة مستقبلاً للإنجازات التي يمكن إسنادها إلى مصادر واضحة."><span class="status-badge unverified">قيد جمع المصادر</span></x-family-page-header>
<section class="section-space"><div class="container"><div class="empty-state"><div class="empty-icon"><i class="bi bi-journal-plus"></i></div><h2 class="h4">لا سجلات منشورة حالياً</h2><p class="text-muted">أزيلت النماذج المختلقة. ستظهر هنا الإنجازات بعد التحقق من صاحبها وصلته بزعبي السلط ومصدر الواقعة.</p></div></div></section>
@endsection
