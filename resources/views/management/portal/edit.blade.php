@extends('layouts.management')
@section('title','موقع العائلة')
@section('page-heading',$family->name_ar.' / موقع العائلة')
@section('content')
@php
    $sectionRows = collect($draft['sections'])->values();
    $statusText = !$setting ? 'لم يُنشر بعد — يرى الزوار الإعدادات الافتراضية المشتقة من بيانات العائلة.' : ($setting->hasUnpublishedChanges() ? 'توجد تعديلات في المسودة لم تُنشر.' : 'المسودة مطابقة للنسخة المنشورة.');
@endphp
<section class="management-panel"><div class="d-flex flex-wrap justify-content-between gap-3">
<div><span class="eyebrow">الهوية والعرض</span><h1 class="h3">موقع العائلة</h1><p class="text-muted mb-1">{{ $statusText }}@if($setting?->published_at) آخر نشر: {{ $setting->published_at->format('Y-m-d H:i') }}.@endif</p>
<p class="small mb-0">العنوان العام الحالي: <a href="{{ $portalUrl }}" target="_blank" rel="noopener">{{ $portalUrl }}</a></p></div>
<div class="d-flex flex-wrap gap-2 align-items-start"><a class="btn btn-outline-primary" href="{{ route('management.portal.preview',$family->slug) }}" target="_blank" rel="noopener"><i class="bi bi-eye"></i> معاينة المسودة</a>
@if($setting?->draft)<form method="post" action="{{ route('management.portal.publish',$family->slug) }}" class="workflow-confirmation">@csrf<button class="btn btn-primary" @disabled(!$setting->hasUnpublishedChanges())><i class="bi bi-upload"></i> نشر المسودة</button></form>@endif</div></div></section>

<form method="post" action="{{ route('management.portal.update',$family->slug) }}" data-portal-settings>@csrf @method('PUT')
<section class="management-panel"><h2 class="h5">الهوية</h2><div class="management-grid">
<label>الاسم المعروض<input class="form-control" name="display_name" value="{{ old('display_name',$draft['display_name']) }}" required maxlength="120">@error('display_name')<span class="field-error">{{ $message }}</span>@enderror</label>
<label>نبذة قصيرة<textarea class="form-control" name="intro" maxlength="400" rows="2">{{ old('intro',$draft['intro']) }}</textarea></label>
<label>تعريف مفصل (نص فقط)<textarea class="form-control" name="description" maxlength="4000" rows="5">{{ old('description',$draft['description']) }}</textarea></label>
<label>عنوان الواجهة<input class="form-control" name="hero_title" value="{{ old('hero_title',$draft['hero_title']) }}" required maxlength="120"></label>
<label>العنوان الفرعي<input class="form-control" name="hero_subtitle" value="{{ old('hero_subtitle',$draft['hero_subtitle']) }}" maxlength="240"></label>
</div></section>

<section class="management-panel"><h2 class="h5">الصور واللون</h2>
<p class="text-muted small">تُختار الصور من مكتبة وسائط العائلة، ويجب أن تكون منشورة للعامة وحقوقها مسموحة. لا تُستخدم صورة على أنها لموقع حقيقي ما لم يكن وصفها كذلك.</p>
<div class="management-grid">
@foreach(['cover_media'=>'صورة الواجهة','logo_media'=>'الشعار'] as $field=>$label)
<label>{{ $label }}<select class="form-select" name="{{ $field }}"><option value="">دون صورة (خلفية زخرفية)</option>@foreach($images as $image)<option value="{{ $image->uuid }}" @selected(old($field,$draft[$field])===$image->uuid)>{{ $image->title }} — {{ $image->ownerEntity?->name_ar }}</option>@endforeach</select>@error($field)<span class="field-error">{{ $message }}</span>@enderror</label>
@endforeach
<fieldset><legend class="h6">لون الموقع</legend><div class="d-flex flex-wrap gap-3">@foreach(config('portals.accents') as $key=>$accent)<label class="d-flex align-items-center gap-2"><input type="radio" name="accent" value="{{ $key }}" @checked(old('accent',$draft['accent'])===$key)><span class="d-inline-block rounded-circle" style="width:22px;height:22px;background:{{ $accent['primary'] }};box-shadow:inset 0 0 0 4px {{ $accent['accent'] }}"></span>{{ $accent['label'] }}</label>@endforeach</div></fieldset>
</div>@if($images->isEmpty())<p class="small text-muted mt-2 mb-0">لا توجد صور عامة في مكتبة الوسائط بعد؛ تُرفع وتُنشر من صفحات الكيانات.</p>@endif</section>

<section class="management-panel"><h2 class="h5">الدعوة الرئيسية والمحتوى</h2><div class="management-grid">
<label>نص الزر الرئيسي<input class="form-control" name="cta_label" value="{{ old('cta_label',$draft['cta_label']) }}" required maxlength="40"></label>
<label>وجهة الزر<select class="form-select" name="cta_target">@foreach(\App\Services\Portal\PortalSettings::CTA_TARGETS as $value=>$label)<option value="{{ $value }}" @selected(old('cta_target',$draft['cta_target'])===$value)>{{ $label }}</option>@endforeach</select></label>
<label>الكيان الرئيسي لمحتوى الموقع<select class="form-select" name="root_entity_id"><option value="">تلقائي (أول كيان رئيسي منشور)</option>@foreach($entities as $entity)<option value="{{ $entity->id }}" @selected((int) old('root_entity_id',$draft['root_entity_id'])===$entity->id)>{{ $entity->name_ar }}{{ $entity->publication_status !== 'published' ? ' (غير منشور — لن يظهر)' : '' }}</option>@endforeach</select></label>
</div></section>

<section class="management-panel"><h2 class="h5">الأقسام وترتيبها</h2><p class="text-muted small">الأقسام الفارغة تعرض رسالة لطيفة ولا تُملأ ببيانات غير معتمدة.</p>
<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>القسم</th><th>ظاهر</th><th>الترتيب</th></tr></thead><tbody>
@foreach($sectionRows as $index=>$row)<tr><td>{{ \App\Services\Portal\PortalSettings::SECTIONS[$row['key']] ?? $row['key'] }}</td><td><input type="hidden" name="sections[{{ $row['key'] }}][visible]" value="0"><input type="checkbox" name="sections[{{ $row['key'] }}][visible]" value="1" @checked($row['visible']) aria-label="إظهار {{ \App\Services\Portal\PortalSettings::SECTIONS[$row['key']] ?? $row['key'] }}"></td><td><input class="form-control form-control-sm" style="max-width:90px" type="number" min="1" max="50" name="sections[{{ $row['key'] }}][order]" value="{{ $index + 1 }}" aria-label="ترتيب {{ \App\Services\Portal\PortalSettings::SECTIONS[$row['key']] ?? $row['key'] }}"></td></tr>@endforeach
</tbody></table></div></section>

<section class="management-panel"><h2 class="h5">التواصل والتذييل</h2><p class="text-muted small">روابط https عامة فقط؛ لا أرقام هواتف شخصية.</p><div class="management-grid">
@for($i=0;$i<6;$i++)@php($link=old('contact_links.'.$i,$draft['contact_links'][$i] ?? ['label'=>'','url'=>'']))<div class="d-flex flex-wrap gap-2"><input class="form-control" style="max-width:200px" name="contact_links[{{ $i }}][label]" value="{{ $link['label'] ?? '' }}" placeholder="العنوان" aria-label="عنوان الرابط {{ $i+1 }}"><input class="form-control" style="max-width:360px" dir="ltr" name="contact_links[{{ $i }}][url]" value="{{ $link['url'] ?? '' }}" placeholder="https://" aria-label="رابط {{ $i+1 }}">@error('contact_links.'.$i.'.url')<span class="field-error">{{ $message }}</span>@enderror</div>@endfor
<label>بريد التواصل العام<input class="form-control" dir="ltr" type="email" name="public_email" value="{{ old('public_email',$draft['public_email']) }}"></label>
<label>نص التذييل<textarea class="form-control" name="footer_text" maxlength="500" rows="2">{{ old('footer_text',$draft['footer_text']) }}</textarea></label>
</div></section>
<div class="mb-4"><button class="btn btn-primary">حفظ المسودة</button></div>
</form>

<section class="management-panel" id="domains"><h2 class="h5">نطاق موقع العائلة</h2>
<p class="text-muted small">يعمل النطاق بعد أن يتحقق مشرف المنصة من DNS وشهادة TLS والوكيل العكسي ويفعّله. حتى ذلك يبقى الموقع على مسار المنصة.</p>
<div class="management-list">@forelse($domains as $domain)<article><div><strong dir="ltr">{{ $domain->host }}</strong><small>{{ \App\Models\FamilyDomain::STATUSES[$domain->status] }} · {{ $domain->kind === 'custom' ? 'نطاق خاص' : 'نطاق فرعي' }}@if($domain->kind==='custom' && $domain->status==='pending_verification') · سجل TXT المطلوب: <code dir="ltr">{{ $domain->verificationRecord() }}</code>@endif</small></div>
@if(in_array($domain->status,['pending_verification','verified']))<form method="post" action="{{ route('management.domains.destroy',[$family->slug,$domain->id]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">سحب الطلب</button></form>@endif</article>@empty<p class="text-muted">لا يوجد نطاق مهيأ.</p>@endforelse</div>
<form method="post" action="{{ route('management.domains.store',$family->slug) }}" class="d-flex flex-wrap gap-2 mt-3">@csrf<input class="form-control" style="max-width:360px" dir="ltr" name="host" value="{{ old('host', $suffix ? $family->slug.'.'.$suffix : '') }}" placeholder="family.example.com" aria-label="النطاق">@error('host')<span class="field-error w-100">{{ $message }}</span>@enderror<button class="btn btn-outline-primary">طلب النطاق</button></form>
</section>
@endsection
