<?php

namespace App\Services\Dashboards;

use App\Models\Family;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\FamilyPermissions;
use App\Services\Community\CommunityEntityAccess;
use App\Services\Portal\PortalSettings;
use App\Support\MaintenanceTools;

/** Presentation of existing policy decisions, never an authorization boundary. */
class AdministrationWorkspace
{
    public function build(User $user, ?Family $family, ?string $tree = null): array
    {
        $tools = MaintenanceTools::states();
        if (! $family) {
            return ['groups' => $user->isSuperAdmin() ? $this->platform($tools) : [], 'tools' => $tools];
        }
        $permissions = app(FamilyPermissions::class)->for($user, $family);
        $has = fn (string $permission) => $family->is_active && in_array($permission, $permissions, true) || $user->isSuperAdmin();
        $genealogy = $has('person.create') || $has('genealogy.review') || $has('source.manage') || $has('branch.manage');
        $manageable = app(CommunityEntityAccess::class)->manageableIds($user, $family);
        $entityQuery = $family->communityEntities()->when($manageable !== null, fn ($query) => $query->whereIn('id', $manageable));
        $current = request()->route('entity');
        $entity = is_numeric($current) ? (clone $entityQuery)->find((int) $current) : null;
        $entity ??= $entityQuery->orderBy('sort_order')->orderBy('id')->first();
        $content = $entity && $user->can('update', $entity);
        $news = $entity && $user->can('update', [$entity, 'news']);
        $publicationScopes = app(CommunityEntityAccess::class)->publicationScopes($user, $family);
        $publisher = collect($publicationScopes)->contains(fn ($ids) => $ids === null || $ids !== []);
        $genealogy = $genealogy || (! $content && ! $news && ! $has('directory.moderate') && $has('person.view-drafts'));
        $capabilities = ['createPerson' => $has('person.create') && $tools['genealogy_administration'], 'sources' => $has('source.manage') && $tools['genealogy_administration'], 'branches' => $has('branch.manage') && $tools['genealogy_administration'], 'genealogy' => $genealogy && $tools['genealogy_administration'], 'review' => $has('genealogy.review') && $tools['genealogy_administration'], 'content' => (bool) $content, 'news' => (bool) $news, 'publisher' => (bool) $publisher, 'directory' => $has('directory.moderate'), 'members' => $has('family.members.manage') && $tools['permissions_administration'], 'settings' => $has('family.settings'), 'audit' => $tools['audit_viewer']];
        $params = [$family->slug, 'tree' => $tree];
        $link = fn (string $label, string $route, array $parameters, string $icon) => ['label' => $label, 'url' => route($route, $parameters), 'icon' => $icon];
        $groups = ['الرئيسية' => [$link('لوحة العائلة', 'management.families.dashboard', $params, 'speedometer2'), $link('فتح موقع العائلة', 'portal.show', [$family->slug], 'box-arrow-up-left')]];
        if ($genealogy && $tools['genealogy_administration']) {
            $groups['الأنساب والشجرة'] = [$link('الشجرة الجامعة', 'genealogy.tree', [...$params, 'private' => 1], 'diagram-3')];
            foreach (['people' => ['الأشخاص', 'person'], 'relationships' => ['علاقات النسب', 'link-45deg'], 'branches' => ['الأفخاذ والفروع', 'bezier2'], 'trees' => ['أشجار العائلة', 'tree']] as $key => [$label, $icon]) {
                $groups['الأنساب والشجرة'][] = $link($label, 'management.sections.index', [$family->slug, $key, 'tree' => $tree], $icon);
            }
        }
        if ($tools['genealogy_administration'] && ($genealogy || $has('person.propose'))) {
            $groups['مراجعة الأنساب'][] = $link($has('genealogy.review') ? 'مراجعة الأنساب' : 'طلبات الإضافة والتعديل', 'management.changes.index', $params, 'check2-square');
            if ($has('source.manage')) {
                $groups['مراجعة الأنساب'][] = $link('المصادر والتوثيق', 'management.sources.index', $params, 'journal-text');
            }
            if ($has('source.manage')) {
                $groups['مراجعة الأنساب'][] = $link('استيراد CSV ومعاينة', 'management.imports.index', $params, 'upload');
            }
            if ($has('source.manage') && $tools['reconciliation']) {
                $groups['مراجعة الأنساب'][] = $link('مطابقة الأسماء التاريخية', 'management.reconciliation.index', $params, 'spellcheck');
            }
            if ($has('person.merge')) {
                $groups['مراجعة الأنساب'][] = $link('دمج السجلات المكررة', 'management.merges.index', $params, 'intersect');
            }
        }
        if ($content || $news) {
            $groups['العائلة والمجتمع'][] = $link('صفحات الكيانات والأفخاذ', 'management.entities.index', [$family->slug], 'diagram-2');
            foreach (['history' => 'التاريخ والتراث', 'diwans' => 'المناطق والدواوين', 'locations' => 'الجغرافيا', 'achievements' => 'الشخصيات والإنجازات', 'archive' => 'الأرشيف', 'media' => 'مكتبة الوسائط'] as $module => $label) {
                if ($content) {
                    $groups['العائلة والمجتمع'][] = $link($label, 'management.entities.content.index', [$family->slug, $entity->id, $module], 'collection');
                }
            }
            if ($news) {
                $groups['الأخبار والمناسبات'] = [$link('الأخبار والإعلانات والتهاني', 'management.entities.content.index', [$family->slug, $entity->id, 'news'], 'newspaper'), $link('المناسبات والفعاليات', 'management.entities.content.index', [$family->slug, $entity->id, 'events'], 'calendar-event')];
            }
            if ($publisher) {
                $groups['الأخبار والمناسبات'][] = $link('مراجعة المحتوى', 'management.entities.review', [$family->slug], 'inbox');
            }
        }
        $groups['دليل السند'] = [$link('ملفي الاختياري في الدليل', 'management.directory.edit', [$family->slug], 'person-vcard'), $link('تصفح دليل السند', 'directory.index', [$family->slug], 'search')];
        if ($has('directory.moderate')) {
            $groups['دليل السند'][] = $link('بلاغات الدليل ومراجعة الانتساب', 'management.directory.review', [$family->slug], 'person-check');
        }
        if ($capabilities['members']) {
            $groups['الأعضاء والصلاحيات'] = [$link('الأعضاء', 'management.sections.index', [$family->slug, 'members', 'tree' => $tree], 'people'), $link('الدعوات', 'management.sections.index', [$family->slug, 'invitations', 'tree' => $tree], 'envelope'), $link('الأدوار والصلاحيات', 'management.roles.index', $params, 'shield-lock')];
        }
        if ($has('family.settings')) {
            $groups['الإعدادات والسجلات'][] = $link('هوية العائلة وإعدادات البوابة', 'management.portal.edit', [$family->slug], 'palette');
        }
        if ($tools['audit_viewer']) {
            $groups['الإعدادات والسجلات'][] = $link('سجل التغييرات', 'management.sections.index', [$family->slug, 'audit', 'tree' => $tree], 'clock-history');
        }
        if ($has('audit.recover') && $tools['audit_viewer']) {
            $groups['الإعدادات والسجلات'][] = $link('سجل الاسترجاع', 'management.recoveries.index', $params, 'arrow-counterclockwise');
        }
        if ($has('family.members.manage') && $tools['genealogy_administration']) {
            $groups['الإعدادات والسجلات'][] = $link('صفات الأشخاص', 'management.attributes.index', [$family->slug], 'tags');
        }
        if ($has('family.members.manage') && $tools['genealogy_administration']) {
            $groups['الإعدادات والسجلات'][] = $link('مطابقات العائلات', 'cross-links.index', ['family' => $family->slug], 'share');
        }
        $settings = app(PortalSettings::class);
        $brand = $settings->published($family);
        $roles = Role::query()->whereNull('deprecated_at')->whereHas('assignments.membership', fn ($query) => $query->where('family_id', $family->id)->where('user_id', $user->id))->pluck('label_ar')->all();

        return ['groups' => $groups, 'tools' => $tools, 'capabilities' => $capabilities, 'entity' => $entity, 'manageable' => $manageable, 'publicationScopes' => $publicationScopes, 'branding' => $brand, 'palette' => config('portals.accents')[$brand['accent']] ?? config('portals.accents.heritage'), 'logo' => $settings->publicImage($family, $brand['logo_media'] ?? null), 'roles' => $roles];
    }

    private function platform(array $tools): array
    {
        $groups = ['نظرة عامة' => [['label' => 'إدارة منصة جذور', 'url' => route('site-admin.dashboard'), 'icon' => 'speedometer2']]];
        foreach (['families' => ['العائلات', 'house'], 'trees' => ['أشجار العائلات', 'tree'], 'users' => ['المستخدمون', 'person-gear'], 'memberships' => ['العضويات', 'people'], 'invitations' => ['الدعوات', 'envelope']] as $key => [$label, $icon]) {
            if ($key === 'trees' && ! $tools['genealogy_administration']) {
                continue;
            }
            if (in_array($key, ['users', 'memberships', 'invitations']) && ! $tools['permissions_administration']) {
                continue;
            }
            $groups['إدارة المنصة'][] = ['label' => $label, 'url' => route('site-admin.resources.index', $key), 'icon' => $icon];
        }
        foreach ([['الأدوار والصلاحيات', 'site-admin.roles.index', 'shield-lock', 'permissions_administration'], ['الروابط بين العائلات', 'cross-links.index', 'share', 'genealogy_administration'], ['صفات الأشخاص المشتركة', 'site-admin.attributes.index', 'tags', 'genealogy_administration'], ['تدقيق البيانات والسيدرز', 'site-admin.seed-audit', 'clipboard-data', 'seed_audit']] as [$label,$route,$icon,$tool]) {
            if ($tools[$tool] && ($tool !== 'seed_audit' || $tools['genealogy_administration'])) {
                $groups['الحوكمة والمراجعة'][] = ['label' => $label, 'url' => route($route), 'icon' => $icon];
            }
        }
        if ($tools['audit_viewer']) {
            $groups['الحوكمة والمراجعة'][] = ['label' => 'سجل التدقيق العام', 'url' => route('site-admin.resources.index', 'audit'), 'icon' => 'clock-history'];
        }
        $groups['تشغيل المنصة'] = [['label' => 'نطاقات العائلات', 'url' => route('site-admin.domains.index'), 'icon' => 'globe2'], ['label' => 'صحة النظام', 'url' => route('site-admin.dashboard').'#systemHeading', 'icon' => 'activity'], ['label' => 'أدوات الصيانة', 'url' => route('site-admin.dashboard').'#maintenance-tools', 'icon' => 'tools']];

        return $groups;
    }
}
