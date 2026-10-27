@php
    $achievements = $content['achievements'];
    $shownAchievements = $category ? $achievements->where('category', $category) : $achievements;
@endphp
<section class="entity-section" id="section-achievements" aria-labelledby="entity-achievements-title" data-module="achievements" data-filter-scope>
    <x-ui.section-head kicker="الشخصيات" title="شخصيات وإنجازات" id="entity-achievements-title" />
    <p class="j-evidence small" role="note" data-disclaimer><i class="bi bi-info-circle" aria-hidden="true"></i> تعرض هذه الصفحة الشخصيات والمناصب والإنجازات وفق المعلومات المتاحة والمدخلة والموثقة في المنصة. ولا تعني القائمة حصر جميع شخصيات الفخذ أو ترتيبًا لأهميتهم أو مكانتهم، ويمكن تقديم معلومات أو وثائق لإضافة شخصيات أخرى أو تحديث البيانات.</p>
    @if($achievements->pluck('category')->unique()->count() > 1)
        <nav class="j-filters" aria-label="تصنيفات غير حصرية">
            <a class="j-chip" href="{{ request()->fullUrlWithQuery(['category' => null]) }}#section-achievements" @if(! $category) aria-current="true" @endif>الكل</a>
            @foreach(\App\Models\EntityAchievement::CATEGORIES as $key => $label)
                @if($achievements->contains('category', $key))<a class="j-chip" href="{{ request()->fullUrlWithQuery(['category' => $key]) }}#section-achievements" @if($category === $key) aria-current="true" @endif>{{ $label }}</a>@endif
            @endforeach
        </nav>
    @endif
    @if($shownAchievements->count() > 6)<label class="j-search mb-2"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">ابحث في الشخصيات</span><input type="search" data-filter-text placeholder="ابحث بالاسم أو المنصب"></label>@endif
    <p class="small text-muted">مرتبة أبجديًا بحسب الاسم. <span data-filter-status role="status"></span></p>
    @if($shownAchievements->isEmpty())
        <x-ui.empty icon="person-badge" title="لا توجد شخصيات منشورة">لا توجد شخصيات أو إنجازات منشورة في هذا النطاق بعد.</x-ui.empty>
    @else
    <div class="j-grid">
        @foreach($shownAchievements as $achievement)
            @php($linked = isset($publicPeople[$achievement->person_id]))
            <article class="j-card content-card" data-record="achievements-{{ $achievement->id }}" data-filter-item data-filter-tags="{{ $achievement->category }} {{ $achievement->kind }}">
                <div class="d-flex flex-wrap gap-2 align-items-baseline">
                    <h3 class="h6 fw-bold mb-1">@if($linked)<a href="{{ route('genealogy.person', [$family->slug, $achievement->person_id, 'tree' => $achievement->person?->familyTree?->slug]) }}" data-person-link>{{ $achievement->person?->full_name_ar }}</a>@else{{ $achievement->person?->full_name_ar }}@endif</h3>
                </div>
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <span class="j-pill">{{ \App\Models\EntityAchievement::KINDS[$achievement->kind] ?? $achievement->kind }}</span>
                    <span class="j-pill">{{ \App\Models\EntityAchievement::CATEGORIES[$achievement->category] ?? $achievement->category }}</span>
                </div>
                <p class="mb-1"><strong>{{ $achievement->title }}</strong>@if($achievement->organization) — {{ $achievement->organization }}@endif @if($achievement->period_label || $achievement->start_year)<span class="small text-muted">({{ $achievement->period_label }}@if($achievement->start_year) {{ $achievement->start_year }}@if($achievement->end_year)–{{ $achievement->end_year }}@endif @endif)</span>@endif</p>
                @if($achievement->description)<p class="mb-2 small">{{ $achievement->description }}</p>@endif
                <x-community.evidence :record="$achievement" :entity="$entity" />
            </article>
        @endforeach
    </div>
    @endif
</section>
