<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\AuditLogger;
use App\Services\Genealogy\FamilyTreeManager;
use App\Services\Genealogy\TreeRootService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TreeController extends Controller
{
    /** Family-scoped tree creation for the family's administrators (formerly served under /site-admin). */
    public function store(Request $request, string $family, FamilyTreeManager $trees): RedirectResponse
    {
        $familyModel = $this->family($family);
        $trees->create($familyModel, $request->user(), $request);

        return back()->with('status', 'تم إنشاء الشجرة.');
    }

    public function details(Request $request, string $family, int $treeId, FamilyTreeManager $trees): RedirectResponse
    {
        $familyModel = $this->family($family);
        $trees->update($familyModel, $familyModel->familyTrees()->findOrFail($treeId), $request->user(), $request);

        return back()->with('status', 'تم تحديث الشجرة.');
    }

    public function update(Request $request, string $family, int $treeId, AuditLogger $audit): RedirectResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('update', $familyModel);
        $treeModel = $familyModel->familyTrees()->findOrFail($treeId);
        $data = $request->validate(TreeRootService::rules());
        app(TreeRootService::class)->configure($treeModel, $request->user(), $data);

        return back()->with('status', 'تم حفظ جذر الشجرة.');
    }

    private function family(string $slug): Family
    {
        $family = Family::query()->where('slug', $slug)->firstOrFail();
        Gate::authorize('update', $family);

        return $family;
    }
}
