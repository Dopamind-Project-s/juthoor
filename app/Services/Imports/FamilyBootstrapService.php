<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\Source;
use App\Models\User;
use App\Services\Access\RoleCatalogSynchronizer;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Prepares a family from a reviewed manifest: family record, main tree, role templates, optional documented sources,
 * draft community entities and pending branches. It is create-only (existing records are reported, never changed) and
 * never fabricates genealogy: no people, fathers, founders, diwans, news, population figures or living statuses are
 * created. A branch founder is linked only when the manifest names an existing person by canonical key; a family without
 * a founder is a valid minimal setup.
 *
 * Manifest shape:
 * {
 *   "family": {"slug": "…", "name_ar": "…", "name_en"?: "…", "short_description"?: "…", "location"?: "…"},
 *   "tree"?: {"slug"?: "main", "name_ar"?: "…", "description"?: "…"},
 *   "sources"?: [{"reference": "…", "title": "…", "source_type"?: "document", "url"?: "…", "notes"?: "…"}],
 *   "entities"?: [{"slug": "…", "name_ar": "…", "entity_type": "tribe|clan|fakhd|sub_fakhd|family_group", "parent"?: "<slug>"}],
 *   "branches"?: [{"slug": "…", "name_ar": "…", "branch_type"?: "…", "parent"?: "<slug>", "founder_key"?: "<canonical key>"}],
 *   "administrator_email"?: "existing account to enroll as family administrator"
 * }
 */
class FamilyBootstrapService
{
    public function __construct(private readonly AuditLogger $audit, private readonly RoleCatalogSynchronizer $roles) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{dry_run: bool, family: string, created: array<int, string>, kept: array<int, string>, skipped: array<int, string>, roles: array<string, mixed>}
     */
    public function bootstrap(array $manifest, bool $dryRun = false, ?User $actor = null): array
    {
        $data = $this->validate($manifest);
        $report = ['dry_run' => $dryRun, 'family' => $data['family']['slug'], 'created' => [], 'kept' => [], 'skipped' => [], 'roles' => []];

        DB::beginTransaction();
        try {
            $roleReport = $this->roles->sync(false, false, $actor);
            $report['roles'] = ['created' => $roleReport['roles']['created'], 'pending_grants' => $roleReport['roles']['pending_grants']];

            $family = Family::query()->where('slug', $data['family']['slug'])->first();
            if ($family) {
                $report['kept'][] = 'family: '.$family->slug.' (لم تُعدّل بياناتها)';
            } else {
                // New families start inactive: an administrator publishes the portal after reviewing it.
                $family = Family::query()->create([...$data['family'], 'is_active' => false]);
                $this->audit->record($actor, $family, $family, 'created', null, $this->audit->snapshot($family));
                $report['created'][] = 'family: '.$family->slug.' (غير نشطة حتى المراجعة)';
            }

            $treeSlug = $data['tree']['slug'] ?? 'main';
            $tree = $family->familyTrees()->where('slug', $treeSlug)->first();
            if ($tree) {
                $report['kept'][] = 'tree: '.$tree->slug;
            } else {
                $tree = $family->familyTrees()->create([
                    'slug' => $treeSlug, 'name_ar' => $data['tree']['name_ar'] ?? 'الشجرة الأساسية', 'description' => $data['tree']['description'] ?? null,
                    'is_primary' => ! $family->familyTrees()->where('is_primary', true)->exists(), 'is_active' => true,
                ]);
                $this->audit->record($actor, $family, $tree, 'created', null, $this->audit->snapshot($tree));
                $report['created'][] = 'tree: '.$tree->slug;
            }

            foreach ($data['sources'] ?? [] as $row) {
                $source = $family->sources()->where('reference', $row['reference'])->first();
                if ($source) {
                    $report['kept'][] = 'source: '.$row['reference'];

                    continue;
                }
                $source = $family->sources()->create([...$row, 'source_type' => $row['source_type'] ?? 'document', 'verification_status' => Source::VERIFICATION_PENDING_REVIEW]);
                $this->audit->record($actor, $family, $source, 'created', null, $this->audit->snapshot($source));
                $report['created'][] = 'source: '.$row['reference'].' (قيد المراجعة)';
            }

            foreach ($data['entities'] ?? [] as $index => $row) {
                $entity = CommunityEntity::query()->where('family_id', $family->id)->where('slug', $row['slug'])->first();
                if ($entity) {
                    $report['kept'][] = 'entity: '.$row['slug'];

                    continue;
                }
                $parent = isset($row['parent']) ? CommunityEntity::query()->where('family_id', $family->id)->where('slug', $row['parent'])->first() : null;
                if (isset($row['parent']) && ! $parent) {
                    $report['skipped'][] = 'entity: '.$row['slug'].' — الكيان الأب «'.$row['parent'].'» غير موجود';

                    continue;
                }
                $entity = CommunityEntity::query()->create([
                    'family_id' => $family->id, 'parent_id' => $parent?->id, 'entity_type' => $row['entity_type'], 'name_ar' => $row['name_ar'], 'slug' => $row['slug'],
                    'family_tree_id' => $tree->id, 'publication_status' => CommunityEntity::STATUS_DRAFT, 'sort_order' => $index,
                ]);
                $this->audit->record($actor, $family, $entity, 'created', null, $this->audit->snapshot($entity));
                $report['created'][] = 'entity: '.$row['slug'].' (مسودة)';
            }

            foreach ($data['branches'] ?? [] as $index => $row) {
                if ($tree->branches()->where('slug', $row['slug'])->exists()) {
                    $report['kept'][] = 'branch: '.$row['slug'];

                    continue;
                }
                $founder = isset($row['founder_key']) ? $tree->people()->where('canonical_key', $row['founder_key'])->first() : null;
                if (isset($row['founder_key']) && ! $founder) {
                    $report['skipped'][] = 'branch: '.$row['slug'].' — المؤسس «'.$row['founder_key'].'» غير موجود في الشجرة؛ لا يُنشأ مؤسس افتراضي';

                    continue;
                }
                $parent = isset($row['parent']) ? $tree->branches()->where('slug', $row['parent'])->first() : null;
                $branch = Branch::query()->create([
                    'family_tree_id' => $tree->id, 'parent_branch_id' => $parent?->id, 'root_person_id' => $founder?->id,
                    'branch_type' => $row['branch_type'] ?? Branch::TYPE_FAMILY_GROUP, 'name_ar' => $row['name_ar'], 'slug' => $row['slug'],
                    'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS, 'sort_order' => $index,
                    'is_active' => false, 'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
                ]);
                $this->audit->record($actor, $family, $branch, 'created', null, $this->audit->snapshot($branch));
                $report['created'][] = 'branch: '.$row['slug'].($founder ? ' (مؤسس: '.$founder->canonical_key.')' : ' (دون مؤسس موثق)').' — غير نشط قيد المراجعة';
            }

            if (isset($data['administrator_email'])) {
                $user = User::query()->where('email', $data['administrator_email'])->first();
                if (! $user) {
                    $report['skipped'][] = 'administrator: لا يوجد حساب بهذا البريد؛ لا تُنشأ حسابات من التهيئة';
                } elseif ($membership = $family->memberships()->where('user_id', $user->id)->first()) {
                    $report['kept'][] = 'administrator: '.$user->email.' (الدور الحالي «'.$membership->role.'» دون تغيير)';
                } else {
                    $membership = $family->memberships()->create(['user_id' => $user->id, 'role' => 'admin']);
                    $this->audit->record($actor, $family, $membership, 'created', null, $this->audit->snapshot($membership));
                    $report['created'][] = 'administrator: '.$user->email;
                }
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function validate(array $manifest): array
    {
        $types = array_keys(CommunityEntity::RANKS);

        return Validator::make($manifest, [
            'family' => ['required', 'array'],
            'family.slug' => ['required', 'alpha_dash', 'max:100', Rule::notIn(config('portals.reserved_labels'))],
            'family.name_ar' => ['required', 'string', 'max:255'],
            'family.name_en' => ['nullable', 'string', 'max:255'],
            'family.short_description' => ['nullable', 'string', 'max:1000'],
            'family.location' => ['nullable', 'string', 'max:255'],
            'tree' => ['nullable', 'array'],
            'tree.slug' => ['nullable', 'alpha_dash', 'max:100'],
            'tree.name_ar' => ['nullable', 'string', 'max:255'],
            'tree.description' => ['nullable', 'string', 'max:2000'],
            'sources' => ['nullable', 'array'],
            'sources.*.reference' => ['required', 'string', 'max:255', 'distinct'],
            'sources.*.title' => ['required', 'string', 'max:255'],
            'sources.*.source_type' => ['nullable', 'string', 'max:50'],
            'sources.*.url' => ['nullable', 'url', 'max:2000'],
            'sources.*.notes' => ['nullable', 'string', 'max:2000'],
            'entities' => ['nullable', 'array'],
            'entities.*.slug' => ['required', 'alpha_dash', 'max:100', 'distinct'],
            'entities.*.name_ar' => ['required', 'string', 'max:255'],
            'entities.*.entity_type' => ['required', Rule::in($types)],
            'entities.*.parent' => ['nullable', 'alpha_dash'],
            'branches' => ['nullable', 'array'],
            'branches.*.slug' => ['required', 'alpha_dash', 'max:100', 'distinct'],
            'branches.*.name_ar' => ['required', 'string', 'max:255'],
            'branches.*.branch_type' => ['nullable', Rule::in(array_keys(Branch::TYPES))],
            'branches.*.parent' => ['nullable', 'alpha_dash'],
            'branches.*.founder_key' => ['nullable', 'string', 'max:255'],
            'administrator_email' => ['nullable', 'email'],
        ])->validate();
    }
}
