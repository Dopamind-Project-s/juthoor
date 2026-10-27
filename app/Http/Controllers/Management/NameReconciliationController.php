<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\Genealogy\NameReconciliationService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Editors and administrators review the recorded ROOT name differences; decisions reuse the assertion actions. */
class NameReconciliationController extends Controller
{
    public function index(Request $request, string $family, NameReconciliationService $reconciliation): View
    {
        abort_unless(\App\Support\MaintenanceTools::enabled('reconciliation'), 403, 'هذه الأداة معطلة من إدارة الموقع.');
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        Gate::authorize('manageSources', $familyModel);
        $tree = app(SelectedFamilyTree::class)->resolve($familyModel);
        $filter = $request->validate(['status' => ['nullable', Rule::in(array_keys(NameReconciliationService::FILTERS))]])['status'] ?? 'open';

        return view('management.reconciliation.index', [
            'family' => $familyModel, 'tree' => $tree, 'filter' => $filter,
            'summary' => $reconciliation->summary($tree),
            'rows' => $reconciliation->rows($tree, $request->user(), $filter),
            'sources' => $familyModel->sources()
                ->when(! $request->user()->can('manageMembers', $familyModel), fn ($query) => $query->whereDoesntHave('citations', fn ($citations) => $citations->whereNotNull('cross_family_link_id')))
                ->orderBy('title')->limit(200)->get(['id', 'title']),
            'canReview' => $request->user()->can('review', $familyModel),
            'canPublish' => $request->user()->can('publish', $familyModel),
        ]);
    }
}
