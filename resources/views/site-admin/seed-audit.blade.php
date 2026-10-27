@extends('layouts.management')
@section('title','تدقيق البيانات والسيدرز')
@section('page-heading','إدارة الموقع / تدقيق البيانات والسيدرز')
@section('content')
@php
    $statusLabel = ['ok' => ['سليم', 'success'], 'warnings' => ['تنبيهات', 'warning'], 'conflicts' => ['تعارضات', 'danger']][$report['status']] ?? [$report['status'], 'secondary'];
    $countLabels = ['people' => 'الأشخاص', 'relationships' => 'العلاقات', 'father_edges' => 'روابط الأبوة', 'branches' => 'الفروع', 'active_branches' => 'فروع نشطة', 'founders' => 'فروع لها مؤسس', 'sources' => 'المصادر', 'citations' => 'الاستشهادات', 'open_claims' => 'روايات مفتوحة'];
@endphp
<section class="management-panel"><div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
<div><span class="eyebrow">قراءة فقط · {{ \Illuminate\Support\Carbon::parse($report['generated_at'])->format('Y-m-d H:i') }}</span><h1 class="h3">تدقيق ما قبل التشغيل: {{ $report['family']['name_ar'] ?? $report['family_slug'] }}</h1>
<p class="text-muted mb-0">لا يشغّل هذا التقرير أي Seeder ولا يعدّل أي سجل. الأرقام سجلات في قاعدة البيانات وليست تعدادًا للسكان. السطر نفسه متاح بالأمر <code>php artisan genealogy:seed-audit --family={{ $report['family_slug'] }} --dry-run</code>.</p></div>
<span class="badge text-bg-{{ $statusLabel[1] }} fs-6">{{ $statusLabel[0] }}</span></div>
<form method="get" class="d-flex gap-2 mt-3"><select name="family" class="form-select w-auto" aria-label="العائلة">@foreach($families as $option)<option value="{{ $option->slug }}" @selected($option->slug === $report['family_slug'])>{{ $option->name_ar }}</option>@endforeach</select><button class="btn btn-outline-primary">عرض</button></form></section>

<section class="management-panel"><h2 class="h5">الأعداد</h2><div class="row g-2">@foreach($report['counts'] as $key=>$value)<div class="col-6 col-md-4 col-lg-3"><div class="placement-card"><small class="d-block text-muted">{{ $countLabels[$key] ?? $key }}</small><strong class="fs-5">{{ $value }}</strong></div></div>@endforeach</div>
@if(array_sum($report['expected']))<p class="small text-muted mt-3 mb-0">المتوقع من ملفات البيانات: الجذر {{ $report['expected']['ready_root_present'] }}/{{ $report['expected']['ready_root'] }} · مسارات المؤسسين {{ $report['expected']['founder_path_present'] }}/{{ $report['expected']['founder_path'] }} · القواسمة {{ $report['expected']['qawasmeh_present'] }}/{{ $report['expected']['qawasmeh'] }} · روايات اختلاف الجذر {{ $report['expected']['root_variant_claims'] }}</p>@endif</section>

<section class="management-panel"><h2 class="h5">التعارضات والتبعيات</h2>
@forelse($report['missing_dependencies'] as $line)<p class="alert alert-danger py-2 mb-2">{{ $line }}</p>@empty<p class="text-muted">لا تبعيات ناقصة.</p>@endforelse
@if($report['identity_conflicts'])<div class="table-responsive"><table class="table table-sm"><thead><tr><th>المفتاح</th><th>النوع</th><th>الموجود</th><th>في ملف البيانات</th></tr></thead><tbody>@foreach($report['identity_conflicts'] as $conflict)<tr><td>{{ $conflict['key'] }}</td><td>{{ $conflict['type'] }}</td><td>{{ $conflict['existing'] }}</td><td>{{ $conflict['expected'] }}</td></tr>@endforeach</tbody></table></div>@else<p class="text-muted mb-1">لا تعارض هوية أو أبوة مع ملفات البيانات.</p>@endif
@php($integrity = $report['integrity'])
<p class="mb-0">السلامة: أبوة ذاتية {{ count($integrity['self_parent']) }} · أكثر من أب أساسي {{ count($integrity['multiple_primary_fathers']) }} · دورات {{ count($integrity['cycles']) }}</p></section>

<section class="management-panel"><h2 class="h5">تعديلات إدارية محفوظة <small class="text-muted">(لن يعيد أي Seeder كتابتها)</small></h2>
@if($report['preserved_edits'])<div class="table-responsive"><table class="table table-sm"><thead><tr><th>السجل</th><th>الحقل</th><th>القيمة الحالية</th><th>قيمة الـ Seeder</th></tr></thead><tbody>@foreach(array_slice($report['preserved_edits'],0,100) as $edit)<tr><td>{{ $edit['record'] }} {{ $edit['key'] }}</td><td>{{ $edit['field'] }}</td><td>{{ $edit['current'] ?? '—' }}</td><td>{{ $edit['seed'] ?? '—' }}</td></tr>@endforeach</tbody></table></div>@if(count($report['preserved_edits'])>100)<p class="small text-muted">عُرض أول 100 من {{ count($report['preserved_edits']) }}.</p>@endif @else<p class="text-muted mb-0">لا فروق بين القيم الحالية وقيم الـ Seeders.</p>@endif</section>

<section class="management-panel"><h2 class="h5">مكررات محتملة</h2>
@forelse($report['duplicate_candidates'] as $group)<p class="mb-1">{{ $group['name'] }} — الأب: {{ $group['father'] ?? 'غير مسجل' }} — السجلات: {{ collect($group['people'])->map(fn($person)=>'#'.$person['id'].($person['key'] ? ' '.$person['key'] : ''))->implode('، ') }}</p>@empty<p class="text-muted mb-0">لا أسماء متطابقة تحت الأب نفسه. لا دمج تلقائي في أي حال.</p>@endforelse</section>

<section class="management-panel"><h2 class="h5">الخصوصية والنشر</h2>
@php($privacy = $report['privacy'])
<p>أحياء: {{ $privacy['living'] }} · حالة حياة غير معروفة: {{ $privacy['unknown'] }} (منها مسند: {{ $privacy['unknown_but_sourced'] }}) · قيد المراجعة: {{ $privacy['pending_review'] }} · قواسمة قيد المراجعة: {{ $privacy['qawasmeh_pending'] }} · ظاهر للعامة: {{ $privacy['public_people'] }} · روايات منشورة: {{ $privacy['public_narratives'] }}</p>
@if($privacy['webroot_datasets'])<div class="alert alert-warning mb-0"><strong>ملفات بيانات خام تحت المجلد العام:</strong> {{ implode('، ', $privacy['webroot_datasets']) }}. تُنقل بعد مراجعة مسارات الاستيراد والخصوصية؛ لا تُحذف تلقائيًا.</div>@endif</section>

<section class="management-panel"><h2 class="h5">الفروع والمؤسسون</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>الفرع</th><th>الأب</th><th>المؤسس</th><th>التوثيق</th><th>نشط</th></tr></thead><tbody>@forelse($report['branches'] as $branch)<tr><td>{{ $branch['name_ar'] }} <code class="small">{{ $branch['slug'] }}</code></td><td>{{ $branch['parent_slug'] ?? '—' }}</td><td>{{ $branch['founder_name'] ?? 'دون مؤسس موثق' }} @if($branch['founder_key'])<code class="small">{{ $branch['founder_key'] }}</code>@endif</td><td>{{ $branch['verification_status'] }}</td><td>{{ $branch['is_active'] ? 'نعم' : 'لا' }}</td></tr>@empty<tr><td colspan="5" class="text-muted">لا فروع.</td></tr>@endforelse</tbody></table></div></section>

@if($report['datasets'])<section class="management-panel"><h2 class="h5">ملفات البيانات والإصدارات</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>الملف</th><th>الإصدار</th><th>sha256</th><th>المطابقة</th></tr></thead><tbody>@foreach($report['datasets'] as $file)<tr><td>{{ $file['label'] }} <code class="small">{{ $file['path'] }}</code></td><td>{{ $file['version'] ?? '—' }}</td><td><code class="small">{{ $file['sha256'] ? substr($file['sha256'],0,16).'…' : 'مفقود' }}</code></td><td>{{ $file['matches'] === null ? '—' : ($file['matches'] ? 'مطابق' : 'مختلف') }}</td></tr>@endforeach</tbody></table></div></section>@endif

<section class="management-panel"><h2 class="h5">العائلات</h2><div class="management-list">@foreach($report['families'] as $item)<article><div><strong>{{ $item['name_ar'] }}</strong><small>{{ $item['slug'] }} · أشجار {{ $item['trees'] }} · أشخاص {{ $item['people'] }} · {{ $item['is_active'] ? 'نشطة' : 'غير نشطة' }}</small></div><a class="btn btn-sm btn-outline-primary" href="{{ route('site-admin.seed-audit',['family'=>$item['slug']]) }}">تدقيق</a></article>@endforeach</div></section>
@endsection
