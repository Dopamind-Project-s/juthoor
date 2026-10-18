<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Source;
use App\Services\Genealogy\GenealogyTraversalService;
use App\Services\Genealogy\PublishedTreeTraversal;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Contracts\View\View;
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
        $branchModel = $tree->branches()->when(request()->boolean('private'), fn ($query) => $query->whereNotNull('root_person_id'), fn ($query) => $query->active())
            ->when(! request()->boolean('private'), fn ($query) => $query->whereIn('verification_status', $this->publicVerificationStatuses())->whereHas('rootPerson', fn ($query) => $query->treeVisible()))
            ->where('slug', $branch)->firstOrFail();

        return $this->treeView($familyModel, $tree, $branchModel);
    }

    public function person(string $family, int $person): View
    {
        [$familyModel, $tree] = $this->publicTree($family);
        $personModel = $tree->people()->when(! request()->boolean('private'), fn ($query) => $query->treeVisible())->whereKey($person)->firstOrFail();
        $profile = app(PublishedTreeTraversal::class)->profile($tree, $personModel, request()->boolean('private'));

        return view('genealogy.person', [
            'family' => $familyModel,
            'tree' => $tree,
            'person' => $personModel,
            ...$profile,
            'foundedBranches' => $tree->branches()->when(request()->boolean('private'), fn ($query) => $query->whereNotNull('root_person_id'), fn ($query) => $query->active())
                ->when(! request()->boolean('private'), fn ($query) => $query->whereIn('verification_status', $this->publicVerificationStatuses())->whereHas('rootPerson', fn ($query) => $query->treeVisible()))
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
            'branches' => $tree->branches()->when(request()->boolean('private'), fn ($query) => $query->whereNotNull('root_person_id'), fn ($query) => $query->active())
                ->when(! request()->boolean('private'), fn ($query) => $query->whereIn('verification_status', $this->publicVerificationStatuses())->whereHas('rootPerson', fn ($query) => $query->treeVisible()))
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

    /** @return array<int, string> */
    private function publicVerificationStatuses(): array
    {
        return [Source::VERIFICATION_VERIFIED, Source::VERIFICATION_SOURCED];
    }
}
