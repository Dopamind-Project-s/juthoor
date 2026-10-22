<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Services\ArabicNameNormalizer;
use App\Services\Dashboards\FamilyHealthService;
use App\Services\Genealogy\BranchMembershipService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FamilyController extends Controller
{
    public function index(Request $request): View
    {
        $query = auth()->user()->isSuperAdmin()
            ? Family::query()
            : auth()->user()->families()->active();
        $families = $query->when($request->filled('q'), fn ($query) => $query->where('name_ar', 'like', '%'.$request->query('q').'%'))->orderBy('name_ar')->paginate(12)->withQueryString();

        return view('management.families.index', compact('families'));
    }

    public function show(Request $request, string $family, ArabicNameNormalizer $normalizer, BranchMembershipService $membership, FamilyHealthService $health): View
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('view', $familyModel);
        $section = $request->route('section');
        if (in_array($section, ['members', 'invitations'])) {
            Gate::authorize('manageMembers', $familyModel);
        }
        $tree = app(SelectedFamilyTree::class)->resolve($familyModel);
        $peopleQuery = $tree->people()->with(['personAttributes', 'familyTree'])->orderBy('full_name_ar');
        if ($request->query('parentage') === 'unknown') {
            $peopleQuery->when($tree->root_person_id, fn ($query) => $query->whereKeyNot($tree->root_person_id))
                ->whereDoesntHave('outgoingRelationships', fn ($query) => $query->where('relationship_type', 'father')->where('is_primary', true));
        }
        if ($request->filled('issue')) {
            $issue = $request->validate(['issue' => ['string', Rule::in(array_keys(FamilyHealthService::ISSUES))]])['issue'];
            $peopleQuery->whereKey($health->people($tree, $issue)->select('people.id'));
        }
        if ($request->filled('q')) {
            $peopleQuery->where('name_search', 'like', '%'.$normalizer->normalize((string) $request->query('q')).'%');
        }
        if ($request->filled('verification')) {
            $peopleQuery->where('verification_status', $request->query('verification'));
        }
        if ($request->filled('branch')) {
            $branch = $tree->branches()->where('slug', $request->query('branch'))->firstOrFail();
            $peopleQuery->whereKey($membership->members($branch)->modelKeys());
        }

        $role = auth()->user()->isSuperAdmin() ? 'super_admin' : auth()->user()->roleFor($familyModel);

        return view('management.families.show', [
            'treeRecords' => $familyModel->familyTrees()->orderBy('name_ar')->paginate(15, ['*'], 'trees_page')->withQueryString(),
            'section' => $section, 'family' => $familyModel, 'tree' => $tree, 'trees' => $familyModel->familyTrees()->get(), 'role' => $role,
            'canEdit' => auth()->user()->canEditFamily($familyModel),
            'isAdmin' => auth()->user()->isSuperAdmin() || $role === FamilyMembership::ROLE_ADMIN,
            'people' => $peopleQuery->paginate(15, ['*'], 'people_page')->withQueryString(),
            'relationships' => $tree->personRelationships()->with(['person', 'relatedPerson'])
                ->when($section === 'relationships' && $request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->whereHas('person', fn ($person) => $person->where('name_search', 'like', '%'.$normalizer->normalize((string) $request->query('q')).'%'))->orWhereHas('relatedPerson', fn ($person) => $person->where('name_search', 'like', '%'.$normalizer->normalize((string) $request->query('q')).'%'))))
                ->latest()->paginate(15, ['*'], 'relationships_page')->withQueryString(),
            'branches' => $tree->branches()->with(['rootPerson', 'parentBranch'])
                ->when($section === 'branches' && $request->filled('q'), fn ($query) => $query->where('name_ar', 'like', '%'.$request->query('q').'%'))
                ->orderBy('sort_order')->paginate(15, ['*'], 'branches_page')->withQueryString(),
            'branchOptions' => $tree->branches()->orderBy('name_ar')->get(['id', 'name_ar', 'slug']),
            'memberships' => $familyModel->memberships()->with('user')
                ->when($section === 'members' && $request->filled('q'), fn ($query) => $query->whereHas('user', fn ($user) => $user->where('name', 'like', '%'.$request->query('q').'%')->orWhere('email', 'like', '%'.$request->query('q').'%')))
                ->when($request->filled('role'), fn ($query) => $query->where('role', $request->query('role')))
                ->orderBy('role')->paginate(15, ['*'], 'memberships_page')->withQueryString(),
            'invitations' => $familyModel->invitations()->with('inviter')
                ->when($section === 'invitations' && $request->filled('q'), fn ($query) => $query->where('email', 'like', '%'.$request->query('q').'%'))
                ->when($request->filled('role'), fn ($query) => $query->where('role', $request->query('role')))
                ->latest()->paginate(15, ['*'], 'invitations_page')->withQueryString(),
            'auditLogs' => $familyModel->auditLogs()->with('user')
                ->visibleToMember($request->user(), $familyModel)
                ->when($section === 'audit' && $request->filled('q'), fn ($query) => $query->where('action', 'like', '%'.$request->query('q').'%'))
                ->latest()->paginate(20, ['*'], 'audit_page')->withQueryString(),
            'statistics' => [
                'people' => $tree->people()->count(),
                'relationships' => $tree->personRelationships()->count(),
                'branches' => $tree->branches()->count(),
                'pending' => $tree->people()->where('verification_status', 'pending_review')->count(),
            ],
        ]);
    }
}
