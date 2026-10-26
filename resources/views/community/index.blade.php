@extends('layouts.app')
@section('title', 'صفحات '.$family->name_ar)
@section('content')
<x-page-header :title="'القبائل والعشائر والأفخاذ — '.$family->name_ar" eyebrow="صفحات الكيانات" description="صفحات تعريفية لمستويات العائلة كما تنشرها إدارتها. الهرم هنا تنظيم اجتماعي وإداري؛ الانتساب النسبي يُعرض في الشجرة الموثقة.">
    <a class="btn btn-outline-primary mt-3" href="{{ route('genealogy.tree', $family->slug) }}"><i class="bi bi-diagram-3"></i> الشجرة الجامعة</a>
    <a class="btn btn-outline-primary mt-3" href="{{ route('directory.index', $family->slug) }}"><i class="bi bi-person-vcard"></i> دليل أبناء العشيرة</a>
</x-page-header>
<section class="section-space"><div class="container">
    @if($rows === [])
        <div class="notice-card" role="status"><i class="bi bi-info-circle"></i><div><h2 class="h6 fw-bold">لا توجد صفحات منشورة بعد</h2><p class="mb-0">لم تنشر إدارة العائلة صفحات للقبيلة أو العشيرة أو الأفخاذ حتى الآن.</p></div></div>
    @else
        <div class="entity-list">
            @foreach($rows as $row)
                <x-community.entity-card :entity="$row['entity']" :family="$family" :depth="$row['depth']" />
            @endforeach
        </div>
    @endif
</div></section>
@endsection
