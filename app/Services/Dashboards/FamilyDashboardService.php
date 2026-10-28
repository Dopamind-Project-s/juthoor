<?php

namespace App\Services\Dashboards;

use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\DirectoryReport;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyRoleAssignment;
use App\Models\FamilyTree;
use App\Models\ImportBatch;
use App\Models\MemberProfile;
use App\Models\User;
use App\Services\Community\CommunityContentModules;
use App\Services\Genealogy\NameReconciliationService;
use App\Services\Portal\FamilyHosts;
use App\Services\Portal\PortalSettings;

/**
 * Builds the family workspace dashboard. Every figure is scoped to the given family
 * (and its selected tree); admin-only figures are omitted for other roles.
 */
class FamilyDashboardService
{
    public function __construct(private readonly FamilyHealthService $health) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Family $family, FamilyTree $tree, User $user): array
    {
        $isAdmin = $user->isSuperAdmin() || $user->roleFor($family) === FamilyMembership::ROLE_ADMIN;

        $workspace = app(AdministrationWorkspace::class)->build($user, $family, $tree->slug);
        $caps = $workspace['capabilities'];
        $contentCounts = [];
        foreach (CommunityContentModules::MODULES as $module => $definition) {
            if (! $caps['content'] && ! ($caps['news'] && in_array($module, ['news', 'events'], true))) {
                continue;
            }
            $contentCounts[$module] = $definition['model']::query()->whereIn('owner_entity_id', $family->communityEntities()->select('id'))
                ->when($workspace['manageable'] !== null, fn ($query) => $query->whereIn('owner_entity_id', $workspace['manageable']))
                ->when($caps['publisher'] && $workspace['publicationScopes'][$module] !== null, fn ($query) => $query->whereIn('owner_entity_id', $workspace['publicationScopes'][$module]))
                ->when(! $caps['publisher'], fn ($query) => $query->where('created_by', $user->id))
                ->toBase()->selectRaw('publication_status, count(*) as total')->groupBy('publication_status')->pluck('total', 'publication_status')->map(fn ($count) => (int) $count)->all();
        }
        $reviewQueue = ChangeRequest::query()->where('family_tree_id', $tree->id)->where('status', 'pending')
            ->when($caps['review'], fn ($query) => $query->where('submitted_by', '!=', $user->id), fn ($query) => $query->where('submitted_by', $user->id));
        $directory = $caps['directory'] ? [
            'profiles' => MemberProfile::query()->where('family_id', $family->id)->where('is_listed', true)->count(),
            'affiliations' => MemberProfile::query()->where('family_id', $family->id)->where('affiliation_status', 'pending')->count(),
            'reports' => DirectoryReport::query()->where('status', 'open')->whereHas('profile', fn ($query) => $query->where('family_id', $family->id))->count(),
        ] : [];

        return [
            'adminWorkspace' => $workspace,
            'contentCounts' => $contentCounts,
            'directoryOverview' => $directory,
            'reviewCount' => $workspace['tools']['genealogy_administration'] ? (clone $reviewQueue)->count() : 0,
            'isAdmin' => $isAdmin,
            'statistics' => ! $caps['genealogy'] ? [] : [
                'people' => $tree->people()->count(),
                'relationships' => $tree->personRelationships()->count(),
                'branches' => $tree->branches()->count(),
                'main_fakhds' => $tree->branches()->where('branch_type', 'fakhd')->count(),
                'sub_fakhds' => $tree->branches()->where('branch_type', 'sub_fakhd')->count(),
                'pending_people' => $tree->people()->where('verification_status', 'pending_review')->count(),
                'pending_changes' => ChangeRequest::query()->where('family_tree_id', $tree->id)->where('status', 'pending')->count(),
                'sources' => $family->sources()->count(),
                'citations' => Citation::query()->where('family_tree_id', $tree->id)->count(),
                'trees' => $family->familyTrees()->count(),
            ],
            'publication' => ! $caps['genealogy'] ? [] : [
                'tree_active' => $tree->is_active,
                'family_active' => $family->is_active,
                'has_root' => $tree->root_person_id !== null,
                'public_people' => $tree->people()->publiclyVisible()->count(),
                'living_people' => $tree->people()->where('is_living', true)->count(),
            ],
            'health' => $caps['genealogy'] ? $this->health->indicators($tree) : [],
            'duplicateGroups' => $caps['genealogy'] ? $this->health->duplicateGroups($tree) : [],
            'pendingChanges' => $workspace['tools']['genealogy_administration'] ? $reviewQueue->orderByDesc('id')->limit(5)->get() : collect(),
            'pendingNameDifferences' => ! $caps['genealogy'] ? 0 : $tree->assertions()->where('import_key', 'like', NameReconciliationService::IMPORT_PREFIX.'%')->where('state', 'proposed')->count(),
            'entityContentPending' => collect($contentCounts)->sum(fn ($counts) => $counts['submitted'] ?? 0),
            'entityCounts' => $family->communityEntities()->toBase()->selectRaw('publication_status, count(*) as total')->groupBy('publication_status')->pluck('total', 'publication_status')->map(fn ($total) => (int) $total)->all(),
            'pendingImports' => ! $caps['genealogy'] ? 0 : ImportBatch::query()->where('family_tree_id', $tree->id)->where('status', 'preview')->count(),
            'branches' => ! $caps['genealogy'] ? collect() : $tree->branches()->whereNull('parent_branch_id')->with('rootPerson')->withCount('childBranches')
                ->orderBy('sort_order')->limit(8)->get(),
            'recentAudit' => ! $workspace['tools']['audit_viewer'] ? collect() : AuditLog::query()->where('family_id', $family->id)->with('user')
                ->visibleToMember($user, $family)
                ->latest()->limit(8)->get(),
            'members' => $caps['members'] ? $family->memberships()->toBase()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role')->map(fn ($total) => (int) $total)->all() : [],
            'portal' => $caps['settings'] || $isAdmin ? $this->portal($family) : [],
            'pendingInvitations' => $caps['members'] ? $family->invitations()->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->count() : null,
            'pendingSharedAncestry' => $isAdmin ? CrossFamilyLink::query()->where('status', 'pending_review')
                ->where(fn ($query) => $query->where('left_family_id', $family->id)->orWhere('right_family_id', $family->id))->count() : null,
        ];
    }

    /**
     * Families the user may open, with their role, for the workspace switcher.
     *
     * @return array<int, array{slug: string, name: string, role: string}>
     */
    /**
     * Family website status: publication of the branding, completeness of its main elements, domain lifecycle and
     * functional role holders.
     *
     * @return array<string, mixed>
     */
    private function portal(Family $family): array
    {
        $setting = $family->portalSetting;
        $published = app(PortalSettings::class)->published($family);

        return [
            'state' => ! $setting?->published ? 'default' : ($setting->hasUnpublishedChanges() ? 'draft' : 'published'),
            'publishedAt' => $setting?->published_at,
            'url' => app(FamilyHosts::class)->portalUrl($family),
            'completeness' => [
                'تعريف مفصل' => filled($published['description']),
                'صورة واجهة معتمدة' => filled($published['cover_media']),
                'شعار' => filled($published['logo_media']),
                'روابط تواصل عامة' => $published['contact_links'] !== [] || filled($published['public_email']),
                'كيان رئيسي منشور' => $family->communityEntities()->whereNull('parent_id')->where('publication_status', 'published')->exists(),
            ],
            'domains' => $family->domains()->orderByDesc('id')->get(['id', 'host', 'status']),
            'roleHolders' => FamilyRoleAssignment::query()->whereHas('membership', fn ($query) => $query->where('family_id', $family->id))->distinct('family_membership_id')->count('family_membership_id'),
        ];
    }

    public static function accessibleFamilies(?User $user): array
    {
        if (! $user) {
            return [];
        }
        if ($user->isSuperAdmin()) {
            return Family::query()->orderBy('name_ar')->get(['name_ar', 'slug'])
                ->map(fn (Family $family) => ['slug' => $family->slug, 'name' => $family->name_ar, 'role' => 'super_admin'])->all();
        }

        return $user->families()->active()->orderBy('name_ar')->get(['families.name_ar', 'families.slug'])
            ->map(fn (Family $family) => ['slug' => $family->slug, 'name' => $family->name_ar, 'role' => $family->pivot->role])->all();
    }

    /** @return array<string, string> */
    public static function roleLabels(): array
    {
        return ['reader' => 'قارئ', 'editor' => 'محرر', 'admin' => 'مدير', 'super_admin' => 'سوبر أدمن'];
    }
}
