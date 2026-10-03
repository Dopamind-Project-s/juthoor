<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\Source;
use App\Services\Genealogy\GenealogyTraversalService;
use Illuminate\Contracts\View\View;

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
        $branchModel = $tree->branches()->active()
            ->whereIn('verification_status', $this->publicVerificationStatuses())
            ->whereHas('rootPerson', fn ($query) => $query->publiclyVisible())
            ->where('slug', $branch)->firstOrFail();

        return $this->treeView($familyModel, $tree, $branchModel);
    }

    public function person(string $family, int $person): View
    {
        [$familyModel, $tree] = $this->publicTree($family);
        $personModel = $tree->people()->publiclyVisible()->whereKey($person)->firstOrFail();
        $ancestorPath = $this->traversal->getAncestorPath($personModel->id)
            ->filter(fn (Person $ancestor): bool => $ancestor->status === Person::STATUS_HISTORICAL
                && in_array($ancestor->verification_status, $this->publicVerificationStatuses(), true))
            ->values();
        $descendantIds = $this->traversal->getDescendantIds($personModel->id, 2);
        $descendants = $tree->people()->publiclyVisible()
            ->whereKey(array_values(array_diff($descendantIds, [$personModel->id])))
            ->orderBy('id')->get();

        return view('genealogy.person', [
            'family' => $familyModel,
            'tree' => $tree,
            'person' => $personModel,
            'ancestorPath' => $ancestorPath,
            'descendants' => $descendants,
            'foundedBranches' => $tree->branches()->active()
                ->whereIn('verification_status', $this->publicVerificationStatuses())
                ->whereHas('rootPerson', fn ($query) => $query->publiclyVisible())
                ->where('root_person_id', $personModel->id)->get(),
        ]);
    }

    private function treeView(Family $family, FamilyTree $tree, ?Branch $focusedBranch): View
    {
        return view('genealogy.tree', [
            'family' => $family,
            'tree' => $tree,
            'focusedBranch' => $focusedBranch,
            'branches' => $tree->branches()->active()
                ->whereIn('verification_status', $this->publicVerificationStatuses())
                ->whereHas('rootPerson', fn ($query) => $query->publiclyVisible())
                ->orderBy('sort_order')->get(),
        ]);
    }

    /** @return array{Family, FamilyTree} */
    private function publicTree(string $family): array
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $tree = $familyModel->familyTrees()->active()->primary()->firstOrFail();

        return [$familyModel, $tree];
    }

    /** @return array<int, string> */
    private function publicVerificationStatuses(): array
    {
        return [Source::VERIFICATION_VERIFIED, Source::VERIFICATION_SOURCED];
    }
}
