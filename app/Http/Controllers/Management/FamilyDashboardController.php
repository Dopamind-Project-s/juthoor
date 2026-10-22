<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\Dashboards\FamilyDashboardService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FamilyDashboardController extends Controller
{
    public function __invoke(Request $request, string $family, SelectedFamilyTree $selection, FamilyDashboardService $dashboard): View
    {
        $family = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('view', $family);
        $tree = $selection->resolve($family);
        $user = $request->user();

        return view('management.families.dashboard', [
            'family' => $family,
            'tree' => $tree,
            'trees' => $family->familyTrees()->orderByDesc('is_primary')->orderBy('name_ar')->get(['id', 'name_ar', 'slug', 'is_primary', 'is_active']),
            'role' => $user->isSuperAdmin() ? 'super_admin' : $user->roleFor($family),
            'canEdit' => $user->canEditFamily($family),
            ...$dashboard->build($family, $tree, $user),
        ]);
    }
}
