@php
    $achievements = $content['achievements'];
    $shownAchievements = $category ? $achievements->where('category', $category) : $achievements;
@endphp
<section class="entity-section" id="section-achievements" aria-labelledby="entity-achievements-title" data-module="achievements">
    <h2 class="h5 fw-bold" id="entity-achievements-title">شخصيات وإنجازات</h2>
    <p class="alert alert-light small" role="note">تعرض هذه الصفحة الشخصيات والمناصب والإنجازات وفق المعلومات المتاحة والمدخلة والموثقة في المنصة. ولا تعني القائمة حصر جميع شخصيات الفخذ أو ترتيبًا لأهميتهم أو مكانتهم، ويمكن تقديم معلومات أو وثائق لإضافة شخصيات أخرى أو تحديث البيانات.</p>
    @if($achievements->pluck('category')->unique()->count() > 1)
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="تصنيفات غير حصرية">
            <a class="btn btn-sm {{ $category ? 'btn-outline-primary' : 'btn-primary' }}" href="{{ request()->fullUrlWithQuery(['category' => null]) }}#section-achievements">الكل</a>
            @foreach(\App\Models\EntityAchievement::CATEGORIES as $key => $label)
                @if($achievements->contains('category', $key))<a class="btn btn-sm {{ $category === $key ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ request()->fullUrlWithQuery(['category' => $key]) }}#section-achievements" @if($category === $key) aria-current="true" @endif>{{ $label }}</a>@endif
            @endforeach
        </nav>
    @endif
    <p class="small text-muted">مرتبة أبجديًا بحسب الاسم.</p>
    @forelse($shownAchievements as $achievement)
        <article class="content-card" data-record="achievements-{{ $achievement->id }}">
            <div class="d-flex flex-wrap gap-2 align-items-baseline">
                <h3 class="h6 fw-bold mb-1">@if(isset($publicPeople[$achievement->person_id]))<a href="{{ route('genealogy.person', [$family->slug, $achievement->person_id, 'tree' => $achievement->person?->familyTree?->slug]) }}">{{ $achievement->person?->full_name_ar }}</a>@else{{ $achievement->person?->full_name_ar }}@endif</h3>
                <span class="badge text-bg-light">{{ \App\Models\EntityAchievement::KINDS[$achievement->kind] ?? $achievement->kind }}</span>
                <span class="badge text-bg-light">{{ \App\Models\EntityAchievement::CATEGORIES[$achievement->category] ?? $achievement->category }}</span>
            </div>
            <p class="mb-1"><strong>{{ $achievement->title }}</strong>@if($achievement->organization) — {{ $achievement->organization }}@endif @if($achievement->period_label || $achievement->start_year)<span class="small text-muted">({{ $achievement->period_label }}@if($achievement->start_year) {{ $achievement->start_year }}@if($achievement->end_year)–{{ $achievement->end_year }}@endif @endif)</span>@endif</p>
            @if($achievement->description)<p class="mb-2">{{ $achievement->description }}</p>@endif
            <x-community.evidence :record="$achievement" :entity="$entity" />
        </article>
    @empty
        <p class="text-muted mb-0" role="status">لا توجد شخصيات أو إنجازات منشورة في هذا النطاق بعد.</p>
    @endforelse
</section>
