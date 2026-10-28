@extends('layouts.family-site')
@section('nav-current', 'heritage')
@section('title', 'تقويم '.$entity->name_ar)
@php
    $first = $month->startOfMonth();
    $offset = ($first->dayOfWeek + 1) % 7;
    $days = $month->daysInMonth;
    $query = fn (array $extra) => route('community.entities.calendar', [$family->slug, $entity->slug, ...array_filter([...request()->except(['page', 'past']), 'scope' => $scope, 'category' => $category, ...$extra])]);
    $weekdays = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
@endphp
@push('styles')<link rel="stylesheet" href="{{ asset('css/community-publications.css') }}">@endpush
@section('content')
<x-page-header :title="'تقويم '.$entity->name_ar" eyebrow="المناسبات والفعاليات" :description="'الأوقات معروضة بتوقيت '.$zone.' ما لم تذكر المناسبة توقيتها.'">
    <a class="btn btn-outline-primary mt-3" href="{{ route('community.entities.show', [$family->slug, $entity->slug, 'scope' => $scope]) }}">صفحة {{ $entity->name_ar }}</a>
</x-page-header>
<section class="section-space"><div class="container">
    <form method="get" class="filter-panel d-flex flex-wrap gap-2 align-items-end mb-3">
        <input type="hidden" name="scope" value="{{ $scope }}"><input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
        <label>التصنيف<select class="form-select" name="category"><option value="">الكل</option>@foreach(\App\Models\CommunityEvent::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>@endforeach</select></label>
        <input type="hidden" name="layout" value="{{ $layout }}">
        <label>بحث<input class="form-control" name="q" type="search" maxlength="100" value="{{ $search }}"></label>
        <label>الحالة<select class="form-select" name="status"><option value="">كل الحالات</option>@foreach([...\App\Models\CommunityEvent::STATUSES, 'rescheduled' => 'أعيدت جدولتها'] as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach</select></label>
        <button class="btn btn-primary">تصفية</button>
        <x-community.scope-switch :scope="$scope" label="نطاق المناسبات" />
    </form>
    @if($subEntities->isNotEmpty())<nav class="d-flex flex-wrap gap-2 mb-3" aria-label="تقويم الكيانات التابعة">@foreach($subEntities as $sub)<a class="btn btn-sm btn-outline-secondary" href="{{ route('community.entities.calendar', [$family->slug, $sub->slug, 'scope' => $scope, 'category' => $category]) }}">{{ $sub->name_ar }}</a>@endforeach</nav>@endif
    <nav class="d-flex gap-2 mb-3" aria-label="طريقة عرض الفعاليات"><a class="btn btn-outline-primary" href="{{ $query(['layout' => 'calendar']) }}" @if($layout === 'calendar') aria-current="page" @endif>التقويم</a><a class="btn btn-outline-primary" href="{{ $query(['layout' => 'list']) }}" @if($layout === 'list') aria-current="page" @endif>القائمة</a></nav>
    @if($layout === 'list')<nav class="d-flex justify-content-between mb-3" aria-label="شهر القائمة"><a href="{{ $query(['month' => $month->subMonth()->format('Y-m')]) }}">الشهر السابق</a><a href="{{ $query(['month' => $month->addMonth()->format('Y-m')]) }}">الشهر التالي</a></nav><h2 class="h5">{{ $month->locale('ar')->translatedFormat('F Y') }}</h2><div class="publication-grid">@forelse($listEvents as $event)<x-community.event-card :event="$event" :family="$family" :visible-media="$visibleMedia ?? []" />@empty<p role="status">لا توجد فعاليات مطابقة لهذا الشهر.</p>@endforelse</div><div class="mt-3">{{ $listEvents->links() }}</div>
    @else
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <a class="btn btn-sm btn-light" href="{{ $query(['month' => $month->subMonth()->format('Y-m')]) }}" aria-label="الشهر السابق"><i class="bi bi-chevron-right"></i></a>
                <h2 class="h5 mb-0" data-calendar-month>{{ $month->locale('ar')->translatedFormat('F Y') }}</h2>
                <a class="btn btn-sm btn-light" href="{{ $query(['month' => $month->addMonth()->format('Y-m')]) }}" aria-label="الشهر التالي"><i class="bi bi-chevron-left"></i></a>
            </div>
            <div class="calendar-grid" role="grid" aria-label="تقويم الشهر">
                @foreach($weekdays as $weekday)<div class="calendar-head" role="columnheader">{{ $weekday }}</div>@endforeach
                @for($i = 0; $i < $offset; $i++)<div class="calendar-cell is-empty" aria-hidden="true"></div>@endfor
                @for($day = 1; $day <= $days; $day++)
                    @php($key = $first->addDays($day - 1)->format('Y-m-d'))
                    <div class="calendar-cell {{ isset($monthEvents[$key]) ? 'has-events' : '' }}" role="gridcell" data-day="{{ $key }}">
                        <span class="calendar-day">{{ $day }}</span>
                        @foreach($monthEvents[$key] ?? [] as $event)<a class="calendar-event status-{{ $event->display_status }}" href="{{ route('community.events.show', [$family->slug, $event->id]) }}" data-calendar-event="{{ $event->id }}">{{ $event->title }}</a>@endforeach
                    </div>
                @endfor
            </div>
        </div>
        <div class="col-lg-5 d-grid gap-3 align-content-start">
            <section class="entity-section" data-upcoming><h2 class="h6 fw-bold">القادمة</h2>@forelse($upcoming as $event)<x-community.event-card :event="$event" :family="$family" :visible-media="$visibleMedia ?? []" />@empty<p class="text-muted mb-0">لا توجد مناسبات قادمة.</p>@endforelse</section>
            <section class="entity-section" data-past><h2 class="h6 fw-bold">السابقة</h2>@forelse($past as $event)<x-community.event-card :event="$event" :family="$family" :visible-media="$visibleMedia ?? []" />@empty<p class="text-muted mb-0">لا توجد مناسبات سابقة.</p>@endforelse<div class="mt-2">{{ $past->links() }}</div></section>
        </div>
    </div>
    @endif
</div></section>
@endsection
