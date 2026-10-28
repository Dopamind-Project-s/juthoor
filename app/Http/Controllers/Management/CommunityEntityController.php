<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\MediaItem;
use App\Services\AuditLogger;
use App\Services\Community\CommunityContentModules;
use App\Services\Community\CommunityEntityAccess;
use App\Services\Community\CommunityEntityPage;
use App\Services\Community\CommunityHierarchy;
use App\Services\Genealogy\SelectedFamilyTree;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tribe, clan, fakhd and branch pages of one family. Writes lock the family row so concurrent re-parenting cannot
 * create a cycle; links to genealogy are references to existing trees and branches, never new people or roots.
 */
class CommunityEntityController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CommunityHierarchy $hierarchy,
        private readonly CommunityEntityAccess $access,
    ) {}

    public function index(Request $request, string $family): View
    {
        $familyModel = $this->family($family);
        Gate::authorize('viewAny', [CommunityEntity::class, $familyModel]);
        $manageable = $this->access->manageableIds($request->user(), $familyModel);
        $publicIds = $this->hierarchy->publicIds($familyModel->id);
        $entities = CommunityEntity::query()->where('family_id', $familyModel->id)
            ->when($manageable !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereIn('id', $manageable)->orWhereIn('id', $publicIds)))->with('branch:id,name_ar', 'familyTree:id,name_ar')->withCount('managers')->get();

        return view('management.entities.index', [
            'family' => $familyModel,
            'tree' => app(SelectedFamilyTree::class)->resolve($familyModel),
            'rows' => $this->hierarchy->ordered($entities),
            'manageable' => $manageable,
            'publicIds' => $publicIds,
            'canCreateRoot' => $request->user()->can('create', [CommunityEntity::class, $familyModel, null]),
            'counts' => $entities->countBy('publication_status')->all(),
        ]);
    }

    public function create(Request $request, string $family): View
    {
        $familyModel = $this->family($family);
        $parent = $request->filled('parent') ? $this->entity($familyModel, $request->integer('parent')) : null;
        Gate::authorize('create', [CommunityEntity::class, $familyModel, $parent]);
        $entity = new CommunityEntity(['family_id' => $familyModel->id, 'parent_id' => $parent?->id, 'publication_status' => CommunityEntity::STATUS_DRAFT]);

        return view('management.entities.form', $this->formData($request, $familyModel, $entity));
    }

    public function store(Request $request, string $family): RedirectResponse
    {
        $familyModel = $this->family($family);
        $entity = new CommunityEntity(['family_id' => $familyModel->id]);
        $this->save($request, $familyModel, $entity);

        return redirect()->route('management.entities.edit', [$familyModel->slug, $entity->id])->with('status', 'تمت إضافة الكيان.');
    }

    public function edit(Request $request, string $family, int $entity): View
    {
        $familyModel = $this->family($family);
        $entityModel = $this->entity($familyModel, $entity);
        Gate::authorize('update', $entityModel);

        return view('management.entities.form', [
            ...$this->formData($request, $familyModel, $entityModel),
            'managers' => $entityModel->managers()->with('user:id,name,username')->get(),
            'members' => $request->user()->can('manageManagers', $entityModel)
                ? FamilyMembership::query()->where('family_id', $familyModel->id)->with('user:id,name,username')->get()->pluck('user')->filter()->sortBy('name')->values()
                : collect(),
            'completeness' => app(CommunityEntityPage::class)->completeness($entityModel),
            'isPublic' => in_array($entityModel->id, $this->hierarchy->publicIds($familyModel->id), true),
            'contentCounts' => collect(CommunityContentModules::MODULES)->map(fn (array $module) => $module['model']::query()->where('owner_entity_id', $entityModel->id)
                ->toBase()->selectRaw('publication_status, count(*) as total')->groupBy('publication_status')->pluck('total', 'publication_status')->map(fn ($total) => (int) $total)->all())->all(),
        ]);
    }

    public function update(Request $request, string $family, int $entity): RedirectResponse
    {
        $familyModel = $this->family($family);
        $entityModel = $this->entity($familyModel, $entity);
        Gate::authorize('update', $entityModel);
        $this->save($request, $familyModel, $entityModel);

        return back()->with('status', 'تم تحديث الكيان.');
    }

    public function storeManager(Request $request, string $family, int $entity): RedirectResponse
    {
        $familyModel = $this->family($family);
        $entityModel = $this->entity($familyModel, $entity);
        Gate::authorize('manageManagers', $entityModel);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('family_memberships', 'user_id')->where('family_id', $familyModel->id)],
            'role' => ['required', Rule::in(array_keys(CommunityEntityManager::ROLES))],
        ], ['user_id.exists' => 'يجب أن يكون المسؤول عضوًا في العائلة.']);

        DB::transaction(function () use ($request, $familyModel, $entityModel, $data): void {
            $manager = CommunityEntityManager::query()->firstOrNew(['community_entity_id' => $entityModel->id, 'user_id' => $data['user_id']]);
            $before = $manager->exists ? $this->audit->snapshot($manager) : null;
            $manager->fill(['role' => $data['role']])->save();
            $this->audit->record($request->user(), $familyModel, $manager, $before ? 'updated' : 'created', $before, $this->audit->snapshot($manager));
        });

        return back()->with('status', 'تم حفظ نطاق المسؤول.');
    }

    public function destroyManager(Request $request, string $family, int $entity, int $manager): RedirectResponse
    {
        $familyModel = $this->family($family);
        $entityModel = $this->entity($familyModel, $entity);
        Gate::authorize('manageManagers', $entityModel);
        $managerModel = $entityModel->managers()->whereKey($manager)->firstOrFail();

        DB::transaction(function () use ($request, $familyModel, $managerModel): void {
            $before = $this->audit->snapshot($managerModel);
            $managerModel->delete();
            $this->audit->record($request->user(), $familyModel, $managerModel, 'deleted', $before, null);
        });

        return back()->with('status', 'تمت إزالة نطاق المسؤول.');
    }

    private function save(Request $request, Family $family, CommunityEntity $entity): void
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique('community_entities')->where('family_id', $family->id)->ignore($entity->id)],
            'entity_type' => ['required', Rule::in(array_keys(CommunityEntity::RANKS))],
            'parent_id' => ['nullable', 'integer'],
            'alternate_names' => ['nullable', 'string', 'max:1000'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'family_tree_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer', Rule::unique('community_entities')->ignore($entity->id)],
            'publication_status' => ['required', Rule::in(array_keys(CommunityEntity::STATUSES))],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'mark_reviewed' => ['nullable', 'boolean'],
            'cover_media_id' => ['nullable', 'integer', Rule::exists('media_items', 'id')->where('family_id', $family->id)->where('category', 'image')],
            'population_estimate' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'population_estimate_year' => ['nullable', 'integer', 'between:1800,2100', 'required_with:population_estimate'],
            // An estimate is shown only with an independent source from the family library.
            'population_estimate_source_id' => ['nullable', 'integer', 'required_with:population_estimate', Rule::exists('sources', 'id')->where('family_id', $family->id)],
        ], ['cover_media_id.exists' => 'صورة الغلاف يجب أن تكون صورة من مكتبة العائلة.', 'population_estimate_source_id.required_with' => 'التعداد التقديري يتطلب مصدرًا مستقلًا من مكتبة المصادر.', 'slug.unique' => 'الرابط المختصر مستخدم لكيان آخر في هذه العائلة.', 'branch_id.unique' => 'لهذا الفرع صفحة كيان أخرى؛ لكل فرع صفحة واحدة.']);

        $parent = isset($data['parent_id']) ? $this->entity($family, (int) $data['parent_id']) : null;
        if (! $entity->exists || (int) $entity->parent_id !== (int) $parent?->id) {
            // Moving an entity needs authority over the destination as well as the entity itself.
            Gate::authorize('create', [CommunityEntity::class, $family, $parent]);
        }
        $branch = isset($data['branch_id']) ? Branch::query()->whereKey($data['branch_id'])->whereHas('familyTree', fn ($trees) => $trees->where('family_id', $family->id))->first() ?? abort(404) : null;
        $treeId = $data['family_tree_id'] ?? $branch?->family_tree_id;
        abort_if($treeId !== null && ! $family->familyTrees()->whereKey($treeId)->exists(), 404);

        try {
            DB::transaction(function () use ($request, $family, $entity, $data, $parent, $branch, $treeId): void {
                Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
                $before = $entity->exists ? $this->audit->snapshot($entity->refresh()) : null;
                $mayPublishBefore = ! $entity->exists || $request->user()->can('publish', $entity);
                $entity->fill([
                    'parent_id' => $parent?->id,
                    'entity_type' => $data['entity_type'],
                    'name_ar' => $data['name_ar'],
                    'name_en' => $data['name_en'] ?? null,
                    'slug' => $data['slug'],
                    'alternate_names' => $this->names($data['alternate_names'] ?? null, $data['name_ar']),
                    'summary' => $data['summary'] ?? null,
                    'description' => $data['description'] ?? null,
                    'family_tree_id' => $treeId,
                    'branch_id' => $branch?->id,
                    'publication_status' => $data['publication_status'],
                    'sort_order' => $data['sort_order'] ?? 0,
                    'cover_media_id' => $data['cover_media_id'] ?? null,
                    'population_estimate' => $data['population_estimate'] ?? null,
                    'population_estimate_year' => isset($data['population_estimate']) ? ($data['population_estimate_year'] ?? null) : null,
                    'population_estimate_source_id' => isset($data['population_estimate']) ? ($data['population_estimate_source_id'] ?? null) : null,
                ]);
                $this->assertPublication($request, $entity, $mayPublishBefore && $request->user()->can('publish', $entity), (bool) ($data['mark_reviewed'] ?? false));
                $entity->save();
                $this->audit->record($request->user(), $family, $entity, $before ? 'updated' : 'created', $before, $this->audit->snapshot($entity));
            });
        } catch (DomainException $exception) {
            $this->integrityFailure($exception);
        }
    }

    /** Publishing, archiving, reviewing or changing a page already published or archived is reserved to publishers. */
    private function assertPublication(Request $request, CommunityEntity $entity, bool $mayPublish, bool $markReviewed): void
    {
        if ($mayPublish) {
            if ($markReviewed) {
                $entity->fill(['reviewed_at' => now(), 'reviewed_by' => $request->user()->id]);
            }

            return;
        }
        if ($entity->exists && $entity->getOriginal('publication_status') !== CommunityEntity::STATUS_DRAFT) {
            throw ValidationException::withMessages(['publication_status' => 'هذه الصفحة منشورة أو مؤرشفة؛ تعديلها من صلاحية مدير العائلة أو مدير الكيان.']);
        }
        if ($entity->publication_status !== CommunityEntity::STATUS_DRAFT) {
            throw ValidationException::withMessages(['publication_status' => 'نشر الصفحة أو أرشفتها من صلاحية مدير العائلة أو مدير الكيان؛ احفظها مسودة.']);
        }
        if ($markReviewed) {
            throw ValidationException::withMessages(['mark_reviewed' => 'تأكيد المراجعة من صلاحية مدير العائلة أو مدير الكيان.']);
        }
    }

    /** @return array<int, string>|null */
    private function names(?string $input, string $name): ?array
    {
        $names = collect(preg_split('/[\r\n،,]+/u', (string) $input))->map(fn (string $value) => trim($value))
            ->filter(fn (string $value) => $value !== '' && $value !== $name)->unique()->values()->all();

        return $names === [] ? null : $names;
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, Family $family, CommunityEntity $entity): array
    {
        $manageable = $this->access->manageableIds($request->user(), $family);
        $excluded = $entity->exists ? $this->hierarchy->descendantIds($entity) : [];
        $parents = CommunityEntity::query()->where('family_id', $family->id)->whereNotIn('id', $excluded)
            // A scoped manager keeps the current parent selectable even when it lies above their scope.
            ->when($manageable !== null, fn ($query) => $query->whereIn('id', [...$manageable, $entity->parent_id ?? 0]))->get();

        return [
            'family' => $family,
            'tree' => app(SelectedFamilyTree::class)->resolve($family),
            'entity' => $entity,
            'parents' => $this->hierarchy->ordered($parents),
            'trees' => $family->familyTrees()->orderBy('name_ar')->get(['id', 'name_ar']),
            'branches' => Branch::query()->whereHas('familyTree', fn ($trees) => $trees->where('family_id', $family->id))
                ->whereDoesntHave('communityEntity', fn ($entities) => $entities->whereKeyNot($entity->id ?? 0))
                ->with('familyTree:id,name_ar', 'rootPerson:id,full_name_ar')->orderBy('name_ar')->get(),
            'canPublish' => $request->user()->can('publish', $entity),
            // The cover is chosen among the family's images; the public page shows it only while it is published for everyone.
            'coverImages' => MediaItem::query()->where('family_id', $family->id)->where('category', 'image')->where('publication_status', '!=', MediaItem::STATUS_ARCHIVED)->orderBy('title')->limit(300)->get(['id', 'title', 'publication_status', 'audience']),
            'estimateSources' => $family->sources()->whereDoesntHave('citations', fn ($citations) => $citations->whereNotNull('cross_family_link_id'))->orderBy('title')->limit(300)->get(['id', 'title']),
            'canCreateRoot' => $request->user()->can('create', [CommunityEntity::class, $family, null]),
        ];
    }

    private function family(string $slug): Family
    {
        return Family::query()->active()->where('slug', $slug)->firstOrFail();
    }

    private function entity(Family $family, int $id): CommunityEntity
    {
        return CommunityEntity::query()->where('family_id', $family->id)->whereKey($id)->firstOrFail();
    }

    private function integrityFailure(DomainException $exception): never
    {
        $message = match ($exception->getMessage()) {
            'The parent entity would create a hierarchy cycle.' => 'اختيار الكيان الأب سيُنشئ دورة في الهرم.',
            'A community entity cannot be its own parent.' => 'لا يمكن أن يكون الكيان أبًا لنفسه.',
            'A community entity cannot be placed above its parent level.' => 'لا يمكن أن يكون مستوى الكيان أعلى من مستوى الكيان الأب.',
            'A community entity cannot be placed below its own children.' => 'لا يمكن جعل مستوى الكيان أدنى من مستوى كياناته التابعة.',
            'A related branch must belong to the related tree.' => 'الفرع النسبي المختار لا ينتمي إلى الشجرة المختارة.',
            default => 'تعذر حفظ الكيان لأنه يخالف سلامة الهرم.',
        };

        throw ValidationException::withMessages(['entity' => $message]);
    }
}
