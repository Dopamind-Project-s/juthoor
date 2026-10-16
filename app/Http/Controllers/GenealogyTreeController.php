<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\Source;
use App\Services\Genealogy\GenealogyTraversalService;
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
        $branchModel = $tree->branches()->active()
            ->when(! request()->boolean('private'), fn ($query) => $query->whereIn('verification_status', $this->publicVerificationStatuses())->whereHas('rootPerson', fn ($query) => $query->publiclyVisible()))
            ->where('slug', $branch)->firstOrFail();

        return $this->treeView($familyModel, $tree, $branchModel);
    }

    public function person(string $family, int $person): View
    {
        [$familyModel, $tree] = $this->publicTree($family);
        $personModel = $tree->people()->when(! request()->boolean('private'), fn ($query) => $query->publiclyVisible())->whereKey($person)->firstOrFail();
        $ancestorPath = $this->traversal->getAncestorPath($personModel->id, null, ! request()->boolean('private'))
            ->filter(fn (Person $ancestor): bool => request()->boolean('private') || ($ancestor->status === Person::STATUS_HISTORICAL && ! $ancestor->is_living
                && in_array($ancestor->verification_status, $this->publicVerificationStatuses(), true)))
            ->values();
        $descendantIds = $this->traversal->getDescendantIds($personModel->id, 2, ! request()->boolean('private'));
        $descendants = $tree->people()->when(! request()->boolean('private'), fn ($query) => $query->publiclyVisible())
            ->whereKey(array_values(array_diff($descendantIds, [$personModel->id])))
            ->orderBy('id')->get();

        return view('genealogy.person', [
            'family' => $familyModel,
            'tree' => $tree,
            'person' => $personModel,
            'father' => $personModel->outgoingRelationships()->where('relationship_type', 'father')->where('is_primary', true)
                ->when(! request()->boolean('private'), fn ($query) => $query->verified()->whereHas('relatedPerson', fn ($person) => $person->publiclyVisible()))->with('relatedPerson')->first()?->relatedPerson,
            'directChildren' => $personModel->incomingRelationships()->where('relationship_type', 'father')->where('is_primary', true)
                ->when(! request()->boolean('private'), fn ($query) => $query->verified()->whereHas('person', fn ($person) => $person->publiclyVisible()))->with('person')->get()->pluck('person'),

            'ancestorPath' => $ancestorPath,
            'descendants' => $descendants,
            'foundedBranches' => $tree->branches()->active()
                ->when(! request()->boolean('private'), fn ($query) => $query->whereIn('verification_status', $this->publicVerificationStatuses())->whereHas('rootPerson', fn ($query) => $query->publiclyVisible()))
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
            'branches' => $tree->branches()->active()
                ->when(! request()->boolean('private'), fn ($query) => $query->whereIn('verification_status', $this->publicVerificationStatuses())->whereHas('rootPerson', fn ($query) => $query->publiclyVisible()))
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
