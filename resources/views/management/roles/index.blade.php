@extends('layouts.management')
@section('title','الأدوار والصلاحيات')
@section('page-heading',$family->name_ar.' / الأدوار والصلاحيات')
@section('content')
@php($catalog = \App\Services\Access\PermissionCatalog::class)
<section class="management-panel"><span class="eyebrow">كتالوج {{ $catalog::VERSION }}</span><h1 class="h3">مصفوفة الأدوار والصلاحيات</h1>
<p class="text-muted">كل صلاحية محصورة في هذه العائلة. أدوار العضوية ثابتة؛ الأدوار الوظيفية تضاف فوقها ولا تنتقل إلى عائلة أخرى. حوكمة الأجداد المشتركين والروابط بين العائلات لمشرف المنصة وحده، ومنع اعتماد الطلب الذاتي قائم لكل الأدوار.</p>
<div class="table-responsive"><table class="table table-sm align-middle role-matrix"><thead><tr><th scope="col">الصلاحية</th>
@foreach(['reader'=>'قارئ','editor'=>'محرر','admin'=>'مدير'] as $label)<th scope="col" class="text-center">{{ $label }}<small class="d-block text-muted">عضوية</small></th>@endforeach
@foreach($roles as $role)<th scope="col" class="text-center">{{ $role->label_ar }}<small class="d-block text-muted">{{ $role->is_system ? 'وظيفي' : 'مخصص' }}{{ $role->customized_at ? ' · معدل' : '' }}</small></th>@endforeach</tr></thead><tbody>
@foreach($catalog::familyPermissionKeys() as $permission)<tr><th scope="row"><span class="d-block">{{ $catalog::PERMISSIONS[$permission]['label'] }}</span><code class="small">{{ $permission }}</code></th>
@foreach(['reader','editor','admin'] as $membershipRole)<td class="text-center">@if(in_array($permission,$catalog::MEMBERSHIP_PERMISSIONS[$membershipRole],true))<i class="bi bi-check-lg text-success" aria-label="مسموح"></i>@else<span class="text-muted" aria-label="غير مسموح">—</span>@endif</td>@endforeach
@foreach($roles as $role)<td class="text-center">@if(in_array($permission,$role->permissionKeys(),true))<i class="bi bi-check-lg text-success" aria-label="مسموح"></i>@else<span class="text-muted" aria-label="غير مسموح">—</span>@endif</td>@endforeach
</tr>@endforeach</tbody></table></div>
@if($roles->isEmpty())<p class="alert alert-info">لم تُثبّت قوالب الأدوار الوظيفية بعد. يثبتها مشرف المنصة بالأمر <code>php artisan rbac:sync</code> أو <code>RolePermissionSeeder</code>.</p>@endif
</section>
<section class="management-panel"><h2 class="h4">أصحاب الأدوار الوظيفية</h2>
<div class="management-list">@forelse($holders as $holder)<article><div><strong>{{ $holder->user->name }}</strong><small>{{ ['reader'=>'قارئ','editor'=>'محرر','admin'=>'مدير'][$holder->role] ?? $holder->role }} · {{ $holder->functionalRoles->map(fn($role)=>$roles->firstWhere('id',$role->id)?->label_ar ?? $role->key)->implode('، ') }}</small></div></article>@empty<p class="text-muted">لا توجد أدوار وظيفية مسندة.</p>@endforelse</div>
@if($canAssign)<a class="btn btn-primary mt-3" href="{{ route('management.sections.index',[$family->slug,'members','tree'=>$tree->slug]) }}">إسناد الأدوار من صفحة الأعضاء</a>@endif
</section>
<details class="management-panel admin-secondary"><summary>صلاحياتي الفعلية داخل هذه العائلة</summary><p class="small text-muted">محصلة دور العضوية والأدوار الوظيفية المسندة؛ لا تنتقل إلى عائلة أخرى. المنح والتعديل يخضعان لسياسات الخادم.</p><ul>@foreach($effectivePermissions as $permission)<li>{{ $catalog::PERMISSIONS[$permission]['label'] ?? $permission }}</li>@endforeach</ul></details>
@endsection
