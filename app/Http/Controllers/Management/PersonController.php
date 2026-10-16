<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StorePersonRequest;
use App\Http\Requests\Management\UpdatePersonRequest;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Services\AuditLogger;
use App\Services\Genealogy\GenealogyDeletionService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GenealogyDeletionService $deletion,
    ) {}

    public function show(string $family, int $person): View
    {
        [$familyModel, $tree] = $this->familyTree($family);
        $personModel = $tree->people()->with(['outgoingRelationships.relatedPerson', 'incomingRelationships.person', 'foundedBranches', 'citations.source', 'outgoingRelationships.citations.source', 'sources'])->whereKey($person)->firstOrFail();
        Gate::authorize('view', $personModel);

        return view('management.people.show', [
            'family' => $familyModel, 'tree' => $tree, 'person' => $personModel,
            'canEdit' => auth()->user()->canEditFamily($familyModel),
        ]);
    }

    public function store(StorePersonRequest $request, string $family): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        Gate::authorize('create', [Person::class, $familyModel]);
        $attributes = $this->attributes($request->validated(), $request->boolean('is_living'));
        $this->ensureCanonicalKeyIsUnique($tree->id, $attributes['canonical_key'] ?? null);

        DB::transaction(function () use ($attributes, $familyModel, $tree, $request): void {
            FamilyTree::query()->whereKey($tree->id)->lockForUpdate()->firstOrFail();
            $this->ensureCanonicalKeyIsUnique($tree->id, $attributes['canonical_key'] ?? null);
            $person = $tree->people()->create($attributes);
            $this->audit->record($request->user(), $familyModel, $person, 'created', null, $this->audit->snapshot($person));
        });

        return back()->with('status', 'تمت إضافة الشخص بنجاح.');
    }

    public function update(UpdatePersonRequest $request, string $family, int $person): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        $personModel = $tree->people()->whereKey($person)->firstOrFail();
        Gate::authorize('update', $personModel);
        $attributes = $this->attributes($request->validated(), $request->boolean('is_living'));
        $this->ensureCanonicalKeyIsUnique($tree->id, $attributes['canonical_key'] ?? null, $personModel->id);

        DB::transaction(function () use ($attributes, $familyModel, $personModel, $request): void {
            FamilyTree::query()->whereKey($personModel->family_tree_id)->lockForUpdate()->firstOrFail();
            $personModel->refresh();
            $this->ensureCanonicalKeyIsUnique($personModel->family_tree_id, $attributes['canonical_key'] ?? null, $personModel->id);
            $before = $this->audit->snapshot($personModel);
            $personModel->update($attributes);
            $this->audit->record($request->user(), $familyModel, $personModel, 'updated', $before, $this->audit->snapshot($personModel));
        });

        return back()->with('status', 'تم تحديث بيانات الشخص.');
    }

    public function destroy(string $family, int $person): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        $personModel = $tree->people()->whereKey($person)->firstOrFail();
        Gate::authorize('delete', $personModel);
        $this->deletion->assertPersonCanBeDeleted($personModel);

        DB::transaction(function () use ($familyModel, $personModel): void {
            FamilyTree::query()->whereKey($personModel->family_tree_id)->lockForUpdate()->firstOrFail();
            $personModel->refresh();
            $this->deletion->assertPersonCanBeDeleted($personModel);
            $before = $this->audit->snapshot($personModel);
            $personModel->delete();
            $this->audit->record(auth()->user(), $familyModel, $personModel, 'deleted', $before, null);
        });

        return redirect()->route('management.sections.index', [$familyModel->slug, 'people', 'tree' => request('tree')])->with('status', 'تم حذف الشخص غير المرتبط.');
    }

    /** @return array{Family, FamilyTree} */
    private function familyTree(string $slug): array
    {
        $family = Family::query()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);

        return [$family, app(SelectedFamilyTree::class)->resolve($family)];
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function attributes(array $validated, bool $isLiving): array
    {
        $validated['is_living'] = $isLiving;

        return $validated;
    }

    private function ensureCanonicalKeyIsUnique(int $treeId, ?string $key, ?int $except = null): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $exists = Person::query()->where('family_tree_id', $treeId)->where('canonical_key', $key)
            ->when($except, fn ($query) => $query->whereKeyNot($except))->exists();

        if ($exists) {
            throw ValidationException::withMessages(['canonical_key' => 'المفتاح المرجعي مستخدم داخل هذه الشجرة.']);
        }
    }
}
