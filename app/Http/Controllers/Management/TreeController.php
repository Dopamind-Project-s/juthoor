<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\AuditLogger;
use App\Services\Genealogy\TreeRootService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TreeController extends Controller
{
    public function update(Request $request, string $family, int $treeId, AuditLogger $audit): RedirectResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('update', $familyModel);
        $treeModel = $familyModel->familyTrees()->findOrFail($treeId);
        $data = $request->validate(TreeRootService::rules());
        app(TreeRootService::class)->configure($treeModel, $request->user(), $data);

        return back()->with('status', 'تم حفظ جذر الشجرة.');
    }
}
