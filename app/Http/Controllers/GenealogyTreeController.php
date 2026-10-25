<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Services\Genealogy\GenealogyTraversalService;
use App\Services\Genealogy\PersonPrivacy;
use App\Services\Genealogy\PublishedTreeTraversal;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class GenealogyTreeController extends Controller
{
    public function __construct(
        private readonly GenealogyTraversalService $traversal,
    ) {}

    public function show(string $family): View
    {
        [$familyModel, $tree] = $this->publicTree($family);

        return $this->treeView($familyModel, $tree, null);
    }

    public function branch(string $family, string $branch): View
    {
        [$familyModel, $tree] = $this->publicTree($family);
        $branchModel = $tree->branches()->when(request()->boolean('private'), fn ($query) => $query->whereNotNull('root_person_id'), fn ($query) => $query->published())
            ->where('slug', $branch)->firstOrFail();

        return $this->treeView($familyModel, $tree, $branchModel);
    }

    public function person(string $family, int $person): View|RedirectResponse
    {
        [$familyModel, $tree] = $this->publicTree($family);
        // A merged identity redirects to its survivor only when the visitor may see the survivor.
        if (($survivor = Person::survivorOf($tree->id, $person))
            && (request()->boolean('private') || $tree->people()->publiclyVisible()->whereKey($survivor->id)->exists())) {
            return redirect()->route('genealogy.person', [$familyModel->slug, $survivor->id, 'tree' => $tree->slug, 'private' => request()->boolean('private') ? 1 : null], 301);
        }
        $personModel = $tree->people()->when(! request()->boolean('private'), fn ($query) => $query->publiclyVisible())->whereKey($person)->firstOrFail();
        $profile = app(PublishedTreeTraversal::class)->profile($tree, $personModel, request()->boolean('private'));

        return view('genealogy.person', [
            'family' => $familyModel,
            'tree' => $tree,
            'person' => $personModel,
            // Public pages read with the public audience; the verified member preview reads with the member's.
            'personFields' => collect(app(PersonPrivacy::class)->fields($personModel, request()->boolean('private') ? request()->user() : null))->where('visible', true)->all(),
            ...$profile,
            'foundedBranches' => $tree->branches()->when(request()->boolean('private'), fn ($query) => $query->whereNotNull('root_person_id'), fn ($query) => $query->published())
                ->where('root_person_id', $personModel->id)->get(),
        ]);
    }

    private function treeView(Family $family, FamilyTree $tree, ?Branch $focusedBranch): View
    {
        return view('genealogy.tree', [
            'family' => $family,
            'tree' => $tree,
            'trees' => $family->familyTrees()->active()->get(),
            'focusedBranch' => $focusedBranch,
            'branches' => $tree->branches()->when(request()->boolean('private'), fn ($query) => $query->whereNotNull('root_person_id'), fn ($query) => $query->published())
                ->orderBy('sort_order')->get(),
        ]);
    }

    /** @return array{Family, FamilyTree} */
    private function publicTree(string $family): array
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        if (request()->boolean('private')) {
            Gate::authorize('view', $familyModel);
            abort_unless(request()->user()?->hasVerifiedEmail(), 403);
        }
        $tree = app(SelectedFamilyTree::class)->resolve($familyModel, true);

        return [$familyModel, $tree];
    }
}
