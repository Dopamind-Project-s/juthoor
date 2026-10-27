<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Services\Genealogy\PersonPlacementService;
use App\Services\Genealogy\PublicationGuard;
use App\Services\Genealogy\SelectedFamilyTree;
use App\Services\Genealogy\SharedAncestryScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RecordController extends Controller
{
    public function create(Request $request, string $family, string $section): View
    {
        return $this->page($family, $section, null, 'create');
    }

    public function show(Request $request, string $family, string $section, int $record): View
    {
        return $this->page($family, $section, $record, 'show');
    }

    public function edit(Request $request, string $family, string $section, int $record): View
    {
        return $this->page($family, $section, $record, 'edit');
    }

    private function page(string $slug, string $section, ?int $id, string $mode): View
    {
        $family = Family::query()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);
        $tree = app(SelectedFamilyTree::class)->resolve($family);
        $title = ['people' => 'الأشخاص', 'relationships' => 'العلاقات', 'branches' => 'الفروع', 'trees' => 'الأشجار', 'members' => 'الأعضاء', 'invitations' => 'الدعوات'][$section] ?? null;
        abort_unless($title, 404);
        $isAdmin = auth()->user()->isSuperAdmin() || auth()->user()->roleFor($family) === 'admin';
        $canEdit = match ($section) {
            'trees', 'members', 'invitations' => $isAdmin,
            'people' => auth()->user()->hasFamilyPermission($family, $mode === 'create' ? 'person.create' : 'person.edit-unpublished'),
            'relationships' => auth()->user()->hasFamilyPermission($family, 'person.edit-unpublished'),
            'branches' => auth()->user()->hasFamilyPermission($family, 'branch.manage'),
        };
        if (in_array($section, ['members', 'invitations'])) {
            Gate::authorize('manageMembers', $family);
        }
        abort_if($mode !== 'show' && ! $canEdit, 403);
        $query = match ($section) {
            'people' => $tree->people(),
            'relationships' => $tree->personRelationships(),
            'branches' => $tree->branches(),
            'trees' => $family->familyTrees(),
            'members' => $family->memberships(),
            'invitations' => $family->invitations(),
        };
        $record = $id ? $query->findOrFail($id) : null;
        $prefillFather = null;
        if ($section === 'people' && $mode === 'create' && request()->filled('father_id')) {
            request()->validate(['father_id' => ['integer']]);
            $prefillFather = $tree->people()->findOrFail(request()->integer('father_id'));
        }
        if ($section === 'people' && request()->boolean('make_root')) {
            Gate::authorize('update', $family);
        }

        if ($section === 'people' && $mode !== 'show') {
            return $this->personForm($family, $tree, $mode, $record, $prefillFather);
        }

        return view('management.records.page', compact('family', 'tree', 'section', 'title', 'mode', 'record', 'canEdit', 'isAdmin', 'prefillFather'));
    }

    /** Guided creation (identity and father, duplicates, optional details, review) and sectioned editing. */
    private function personForm(Family $family, FamilyTree $tree, string $mode, ?Person $person, ?Person $prefillFather): View
    {
        $user = auth()->user();
        $placement = app(PersonPlacementService::class);
        $guard = app(PublicationGuard::class);
        if ($person) {
            Gate::authorize('update', $person);
        } else {
            Gate::authorize('create', [Person::class, $family]);
        }
        $sharedFamilies = $person ? app(SharedAncestryScope::class)->impactedFamilies($person) : collect();
        $isPublic = $person && $guard->isPublic($person, $person->getAttributes());
        $canPublish = $guard->canPublish($user, $family);

        return view('management.people.form', [
            'family' => $family, 'tree' => $tree, 'mode' => $mode, 'person' => $person, 'prefillFather' => $prefillFather,
            'fatherContext' => $prefillFather ? $placement->fatherContext($tree, $prefillFather) : null,
            'impact' => $person ? $placement->impact($tree, $person) : null,
            'isPublic' => $isPublic, 'canPublish' => $canPublish,
            'sharedFamilies' => $sharedFamilies,
            // Published records change through reviewed proposals; shared ancestors only through the platform reviewer.
            'readOnlyReason' => match (true) {
                $sharedFamilies->isNotEmpty() && ! $user->isSuperAdmin() => 'shared',
                $isPublic && ! $canPublish => 'published',
                default => null,
            },
            'proposeUrl' => $person && $user->can('propose', $family) ? route('management.changes.create', [$family->slug, 'tree' => $tree->slug, 'kind' => 'person', 'target' => $person->id]) : null,
            'assertionCount' => $person ? $person->assertions()->count() : 0,
            'citationCount' => $person ? $person->citations()->count() + $person->sources()->count() : 0,
            'pendingChanges' => $person ? ChangeRequest::query()->where('family_tree_id', $tree->id)->where('target_type', 'person')->where('target_id', $person->id)->where('status', 'pending')->count() : 0,
        ]);
    }
}
