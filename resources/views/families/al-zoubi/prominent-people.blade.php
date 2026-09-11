@extends('layouts.family')
@section('title','أبرز الشخصيات')
@section('content')
<x-family-page-header title="أبرز الشخصيات" icon="person-badge" description="ملفات مختارة تُظهر مستقبلًا الأثر والسيرة والمصدر، مع احترام الدقة والخصوصية."><span class="status-badge unverified">أسماء وصفات تجريبية فقط</span></x-family-page-header>
<section class="section-space"><div class="container"><div class="filter-chips" role="group" aria-label="تصنيفات الشخصيات">@foreach(['الكل','تاريخية','أكاديمية','طبية','هندسية','أعمال','ثقافية','اجتماعية','رياضية'] as $label)<button class="{{ $loop->first ? 'active' : '' }}">{{ $label }}</button>@endforeach</div><div class="row g-4 mt-2">@foreach([['شخصية تجريبية 01','مجال أكاديمي'],['شخصية تجريبية 02','مجال طبي'],['شخصية تجريبية 03','مجال هندسي'],['شخصية تجريبية 04','مجال الأعمال'],['شخصية تجريبية 05','مجال ثقافي'],['شخصية تجريبية 06','مجال اجتماعي']] as [$name,$role])<div class="col-md-6 col-lg-4"><x-person-profile-card :name="$name" :role="$role" /></div>@endforeach</div></div></section>
@endsection
