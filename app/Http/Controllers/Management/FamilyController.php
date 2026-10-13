<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Services\ArabicNameNormalizer;
use App\Services\Genealogy\BranchMembershipService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FamilyController extends Controller
{
    public function index(): View
    {
        $query = auth()->user()->isSuperAdmin()
            ? Family::query()
            : auth()->user()->families()->active();
        $families = $query->orderBy('name_ar')->paginate(12)->withQueryString();

        return view('management.families.index', compact('families'));
    }

    public function show(Request $request, string $family, ArabicNameNormalizer $normalizer, BranchMembershipService $membership): View
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('view', $familyModel);
        $tree = app(SelectedFamilyTree::class)->resolve($familyModel);
        $peopleQuery = $tree->people()->orderBy('full_name_ar');
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
            'family' => $familyModel, 'tree' => $tree, 'trees' => $familyModel->familyTrees()->get(), 'role' => $role,
            'canEdit' => auth()->user()->canEditFamily($familyModel),
            'isAdmin' => auth()->user()->isSuperAdmin() || $role === FamilyMembership::ROLE_ADMIN,
            'people' => $peopleQuery->paginate(15, ['*'], 'people_page')->withQueryString(),
            'relationships' => $tree->personRelationships()->with(['person', 'relatedPerson'])->latest()->paginate(15, ['*'], 'relationships_page')->withQueryString(),
            'branches' => $tree->branches()->with(['rootPerson', 'parentBranch'])->orderBy('sort_order')->paginate(15, ['*'], 'branches_page')->withQueryString(),
            'branchOptions' => $tree->branches()->orderBy('name_ar')->get(['id', 'name_ar', 'slug']),
            'memberships' => $familyModel->memberships()->with('user')->orderBy('role')->paginate(15, ['*'], 'memberships_page')->withQueryString(),
            'invitations' => $familyModel->invitations()->with('inviter')->latest()->paginate(15, ['*'], 'invitations_page')->withQueryString(),
            'auditLogs' => $familyModel->auditLogs()->with('user')->latest()->paginate(20, ['*'], 'audit_page')->withQueryString(),
            'statistics' => [
                'people' => $tree->people()->count(),
                'relationships' => $tree->personRelationships()->count(),
                'branches' => $tree->branches()->count(),
                'pending' => $tree->people()->where('verification_status', 'pending_review')->count(),
            ],
        ]);
    }
}
