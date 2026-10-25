<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\ConnectedPersonRequest;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\Source;
use App\Services\AuditLogger;
use App\Services\Genealogy\ConnectedPersonService;
use App\Services\Genealogy\GenealogyDeletionService;
use App\Services\Genealogy\SelectedFamilyTree;
use App\Services\Genealogy\SharedAncestryScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PersonController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GenealogyDeletionService $deletion,
    ) {}

    public function show(string $family, int $person): View|RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        if ($survivor = Person::survivorOf($tree->id, $person)) {
            return redirect()->route('management.people.show', [$familyModel->slug, $survivor->id, 'tree' => $tree->slug])->with('status', 'دُمج السجل #'.$person.' في هذا السجل.');
        }
        $personModel = $tree->people()->with(['outgoingRelationships.relatedPerson', 'incomingRelationships.person', 'foundedBranches', 'citations.source', 'outgoingRelationships.citations.source', 'sources'])->whereKey($person)->firstOrFail();
        Gate::authorize('view', $personModel);

        return view('management.people.show', [
            'family' => $familyModel, 'tree' => $tree, 'person' => $personModel,
            'canEdit' => auth()->user()->canEditFamily($familyModel),
            'assertions' => $personModel->assertions()->with(['citations.source', 'relatedPerson', 'creator', 'reviewer', 'changeRequests' => fn ($query) => $query->where('status', 'pending')])->orderBy('fact_type')->orderBy('id')->get()->groupBy('fact_type'),
            'factSources' => Source::query()->where('family_id', $familyModel->id)
                ->when(! auth()->user()->can('manageMembers', $familyModel), fn ($query) => $query->whereDoesntHave('citations', fn ($citations) => $citations->whereNotNull('cross_family_link_id')))
                ->orderBy('title')->limit(200)->get(['id', 'title', 'source_type']),
            'sharedFamilies' => app(SharedAncestryScope::class)->impactedFamilies($personModel),
        ]);
    }

    public function store(ConnectedPersonRequest $request, string $family): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        Gate::authorize('create', [Person::class, $familyModel]);
        $person = app(ConnectedPersonService::class)->save($tree, $request->user(), $request->validated());

        return redirect()->route('management.people.show', [$familyModel->slug, $person->id, 'tree' => $tree->slug])->with('status', 'تمت إضافة الشخص بنجاح.');
    }

    public function update(ConnectedPersonRequest $request, string $family, int $person): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        $personModel = $tree->people()->findOrFail($person);
        Gate::authorize('update', $personModel);
        app(ConnectedPersonService::class)->save($tree, $request->user(), $request->validated(), $personModel);

        return redirect()->route('management.people.show', [$familyModel->slug, $personModel->id, 'tree' => $tree->slug])->with('status', 'تم تحديث بيانات الشخص.');
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
}
