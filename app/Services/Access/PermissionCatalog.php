<?php

namespace App\Services\Access;

use App\Models\FamilyMembership;

/**
 * The versioned permission catalog. Membership roles (reader / editor / admin) keep their established rights in code;
 * functional role templates add narrowly scoped rights on top of a membership and never cross a family boundary.
 * Platform governance (shared ancestors, cross-family links, platform settings) stays with super admins and is not
 * grantable through a family role.
 *
 * Changing a template here does not silently change installed roles: RoleCatalogSynchronizer reports the difference, removes
 * withdrawn catalog grants, and adds new grants only when upgrades are explicitly applied.
 */
final class PermissionCatalog
{
    public const VERSION = '2026.10.27';

    public const SCOPE_FAMILY = 'family';

    public const SCOPE_PLATFORM = 'platform';

    /** @var array<string, array{scope: string, label: string}> */
    public const PERMISSIONS = [
        'person.view-drafts' => ['scope' => self::SCOPE_FAMILY, 'label' => 'عرض الأشخاص غير المنشورين داخل الإدارة'],
        'person.propose' => ['scope' => self::SCOPE_FAMILY, 'label' => 'اقتراح إضافة شخص أو تصحيح بياناته'],
        'person.create' => ['scope' => self::SCOPE_FAMILY, 'label' => 'إضافة أشخاص غير منشورين مباشرة'],
        'person.edit-unpublished' => ['scope' => self::SCOPE_FAMILY, 'label' => 'تعديل الأشخاص والعلاقات غير المنشورة'],
        'genealogy.review' => ['scope' => self::SCOPE_FAMILY, 'label' => 'مراجعة طلبات تغيير الأنساب واعتمادها أو رفضها'],
        'genealogy.publish' => ['scope' => self::SCOPE_FAMILY, 'label' => 'نشر الأنساب المعتمدة وتعديل المنشور مباشرة'],
        'privacy.manage' => ['scope' => self::SCOPE_FAMILY, 'label' => 'ضبط خصوصية حقول الأشخاص'],
        'source.manage' => ['scope' => self::SCOPE_FAMILY, 'label' => 'إدارة مصادر الأنساب واستشهاداتها'],
        'branch.manage' => ['scope' => self::SCOPE_FAMILY, 'label' => 'إدارة الفروع والأفخاذ غير المنشورة'],
        'person.merge' => ['scope' => self::SCOPE_FAMILY, 'label' => 'دمج سجلات الأشخاص المكررة والتراجع عنه'],
        'audit.recover' => ['scope' => self::SCOPE_FAMILY, 'label' => 'استرجاع التغييرات من سجل التدقيق'],
        'family.settings' => ['scope' => self::SCOPE_FAMILY, 'label' => 'إعدادات العائلة وأشجارها وهوية البوابة'],
        'family.members.manage' => ['scope' => self::SCOPE_FAMILY, 'label' => 'دعوة الأعضاء وإدارة عضوياتهم'],
        'role.assign' => ['scope' => self::SCOPE_FAMILY, 'label' => 'إسناد الأدوار الوظيفية لأعضاء العائلة'],
        'content.edit' => ['scope' => self::SCOPE_FAMILY, 'label' => 'تحرير محتوى الكيانات والأرشيف والوسائط (مسودات)'],
        'content.publish' => ['scope' => self::SCOPE_FAMILY, 'label' => 'مراجعة محتوى الكيانات والأرشيف والوسائط ونشره'],
        'news.edit' => ['scope' => self::SCOPE_FAMILY, 'label' => 'تحرير الأخبار والمناسبات (مسودات)'],
        'news.publish' => ['scope' => self::SCOPE_FAMILY, 'label' => 'مراجعة الأخبار والمناسبات ونشرها'],
        'news.death-notices' => ['scope' => self::SCOPE_FAMILY, 'label' => 'اعتماد أخبار الوفاة الحساسة'],
        'directory.moderate' => ['scope' => self::SCOPE_FAMILY, 'label' => 'الإشراف على دليل الأعضاء والبلاغات'],
        'platform.govern' => ['scope' => self::SCOPE_PLATFORM, 'label' => 'حوكمة المنصة: الأجداد المشتركون والروابط بين العائلات (مشرف المنصة فقط)'],
    ];

    /**
     * Rights each membership role has always had; reproduced here so the matrix is explicit and testable.
     *
     * @var array<string, array<int, string>>
     */
    public const MEMBERSHIP_PERMISSIONS = [
        FamilyMembership::ROLE_READER => ['person.view-drafts', 'person.propose'],
        FamilyMembership::ROLE_EDITOR => ['person.view-drafts', 'person.propose', 'person.create', 'person.edit-unpublished', 'source.manage', 'branch.manage', 'content.edit', 'news.edit'],
        FamilyMembership::ROLE_ADMIN => [
            'person.view-drafts', 'person.propose', 'person.create', 'person.edit-unpublished', 'genealogy.review', 'genealogy.publish',
            'privacy.manage', 'source.manage', 'branch.manage', 'person.merge', 'audit.recover', 'family.settings', 'family.members.manage',
            'role.assign', 'content.edit', 'content.publish', 'news.edit', 'news.publish', 'news.death-notices', 'directory.moderate',
        ],
    ];

    /**
     * Functional role templates, assignable to members of one family on top of their membership role.
     *
     * @var array<string, array{label: string, description: string, permissions: array<int, string>}>
     */
    public const ROLES = [
        'genealogy-manager' => [
            'label' => 'مدير الأنساب',
            'description' => 'ينسق الأنساب والمصادر والفروع، ويراجع الطلبات وينشر المعتمد. لا يدير الأعضاء ولا المحتوى.',
            'permissions' => ['person.create', 'person.edit-unpublished', 'genealogy.review', 'genealogy.publish', 'privacy.manage', 'source.manage', 'branch.manage'],
        ],
        'genealogy-editor' => [
            'label' => 'محرر الأنساب',
            'description' => 'يضيف الأشخاص ويعدل غير المنشور ويقترح التصحيحات؛ لا يعتمد ولا ينشر.',
            'permissions' => ['person.create', 'person.edit-unpublished', 'source.manage'],
        ],
        'genealogy-reviewer' => [
            'label' => 'مراجع الأنساب',
            'description' => 'يراجع مقترحات الآخرين ويعتمدها أو يرفضها بسبب مكتوب؛ لا يعتمد طلبه بنفسه ولا يحرر مباشرة.',
            'permissions' => ['genealogy.review'],
        ],
        'content-manager' => [
            'label' => 'مدير المحتوى',
            'description' => 'يحرر محتوى الكيانات والأرشيف والوسائط وينشره؛ لا صلاحية له على الأنساب.',
            'permissions' => ['content.edit', 'content.publish'],
        ],
        'news-editor' => [
            'label' => 'محرر الأخبار',
            'description' => 'يعد الأخبار والمناسبات ويرسلها للمراجعة.',
            'permissions' => ['news.edit'],
        ],
        'news-publisher' => [
            'label' => 'مراجع وناشر الأخبار',
            'description' => 'يراجع الأخبار والمناسبات وينشرها؛ أخبار الوفاة تحتاج صلاحية مستقلة.',
            'permissions' => ['news.edit', 'news.publish'],
        ],
        'death-notice-reviewer' => [
            'label' => 'مراجع أخبار الوفاة',
            'description' => 'يتحقق من أخبار الوفاة الحساسة ويعتمد نشرها بعد الموافقة.',
            'permissions' => ['news.edit', 'news.publish', 'news.death-notices'],
        ],
        'directory-moderator' => [
            'label' => 'مشرف الدليل',
            'description' => 'يعالج بلاغات الدليل ويوقف الملفات أو يعيدها دون الاطلاع على حقول الاتصال المحجوبة.',
            'permissions' => ['directory.moderate'],
        ],
    ];

    /**
     * Renamed keys (old => new). The synchronizer moves existing grants to the new key and reports it.
     *
     * @var array<string, string>
     */
    public const RENAMED = [];

    /** Content modules that the news permissions cover; every other module needs the content permissions. */
    public const NEWS_MODULES = ['news', 'events'];

    /** @return array<int, string> */
    public static function familyPermissionKeys(): array
    {
        return array_keys(array_filter(self::PERMISSIONS, fn (array $permission) => $permission['scope'] === self::SCOPE_FAMILY));
    }
}
