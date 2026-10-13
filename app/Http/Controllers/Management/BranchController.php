<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreBranchRequest;
use App\Http\Requests\Management\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Services\AuditLogger;
use App\Services\Genealogy\GenealogyDeletionService;
use App\Services\Genealogy\SelectedFamilyTree;
use DomainException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GenealogyDeletionService $deletion,
    ) {}

    public function store(StoreBranchRequest $request, string $family): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        Gate::authorize('create', [Branch::class, $familyModel]);
        $attributes = $this->attributes($request, $tree->id);
        $this->ensureSlugIsUnique($tree->id, $attributes['slug']);

        try {
            DB::transaction(function () use ($attributes, $familyModel, $tree, $request): void {
                FamilyTree::query()->whereKey($tree->id)->lockForUpdate()->firstOrFail();
                $this->ensureSlugIsUnique($tree->id, $attributes['slug']);
                $branch = $tree->branches()->create($attributes);
                $this->audit->record($request->user(), $familyModel, $branch, 'created', null, $this->audit->snapshot($branch));
            });
        } catch (DomainException $exception) {
            $this->integrityFailure($exception);
        }

        return back()->with('status', 'تمت إضافة الفرع.');
    }

    public function update(UpdateBranchRequest $request, string $family, int $branch): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        $branchModel = $tree->branches()->whereKey($branch)->firstOrFail();
        Gate::authorize('update', $branchModel);
        $attributes = $this->attributes($request, $tree->id);
        $this->ensureSlugIsUnique($tree->id, $attributes['slug'], $branchModel->id);

        try {
            DB::transaction(function () use ($attributes, $familyModel, $branchModel, $request): void {
                FamilyTree::query()->whereKey($branchModel->family_tree_id)->lockForUpdate()->firstOrFail();
                $branchModel->refresh();
                $this->ensureSlugIsUnique($branchModel->family_tree_id, $attributes['slug'], $branchModel->id);
                $before = $this->audit->snapshot($branchModel);
                $branchModel->update($attributes);
                $this->audit->record($request->user(), $familyModel, $branchModel, 'updated', $before, $this->audit->snapshot($branchModel));
            });
        } catch (DomainException $exception) {
            $this->integrityFailure($exception);
        }

        return back()->with('status', 'تم تحديث الفرع.');
    }

    public function destroy(string $family, int $branch): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        $branchModel = $tree->branches()->whereKey($branch)->firstOrFail();
        Gate::authorize('delete', $branchModel);
        $this->deletion->assertBranchCanBeDeleted($branchModel);

        DB::transaction(function () use ($familyModel, $branchModel): void {
            FamilyTree::query()->whereKey($branchModel->family_tree_id)->lockForUpdate()->firstOrFail();
            $branchModel->refresh();
            $this->deletion->assertBranchCanBeDeleted($branchModel);
            $before = $this->audit->snapshot($branchModel);
            $branchModel->delete();
            $this->audit->record(auth()->user(), $familyModel, $branchModel, 'deleted', $before, null);
        });

        return back()->with('status', 'تم حذف الفرع دون حذف مؤسسه.');
    }

    /** @return array{Family, FamilyTree} */
    private function familyTree(string $slug): array
    {
        $family = Family::query()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);

        return [$family, app(SelectedFamilyTree::class)->resolve($family)];
    }

    /** @return array<string, mixed> */
    private function attributes(FormRequest $request, int $treeId): array
    {
        if (! $request->boolean('is_active')) {
            $isActive = false;
        } else {
            $isActive = true;
        }

        $rootExists = Person::query()->where('family_tree_id', $treeId)
            ->whereKey($request->integer('root_person_id'))->exists();
        $parentId = $request->filled('parent_branch_id') ? $request->integer('parent_branch_id') : null;
        $parentExists = $parentId === null || Branch::query()->where('family_tree_id', $treeId)->whereKey($parentId)->exists();

        if (! $rootExists || ! $parentExists) {
            abort(404);
        }

        return [
            'name_ar' => (string) $request->string('name_ar'),
            'slug' => (string) $request->string('slug'),
            'root_person_id' => $request->integer('root_person_id'),
            'parent_branch_id' => $parentId,
            'description' => $request->input('description'),
            'branch_type' => Branch::TYPE_FAMILY_GROUP,
            'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS,
            'is_active' => $isActive,
            'verification_status' => (string) $request->string('verification_status'),
        ];
    }

    private function ensureSlugIsUnique(int $treeId, string $slug, ?int $except = null): void
    {
        $exists = Branch::query()->where('family_tree_id', $treeId)->where('slug', $slug)
            ->when($except, fn ($query) => $query->whereKeyNot($except))->exists();

        if ($exists) {
            throw ValidationException::withMessages(['slug' => 'الرابط المختصر مستخدم داخل هذه الشجرة.']);
        }
    }

    private function integrityFailure(DomainException $exception): never
    {
        $message = match ($exception->getMessage()) {
            'A nested branch founder must descend from the parent branch founder.' => 'يجب أن ينحدر مؤسس الفرع الفرعي من مؤسس الفرع الأب.',
            'The parent branch would create a branch hierarchy cycle.' => 'اختيار الفرع الأب سيُنشئ دورة غير صالحة.',
            default => 'تعذر حفظ الفرع لأنه يخالف سلامة الشجرة.',
        };

        throw ValidationException::withMessages(['branch' => $message]);
    }
}
