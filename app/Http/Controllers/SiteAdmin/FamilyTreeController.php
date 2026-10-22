<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\Genealogy\FamilyTreeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Platform-level tree administration for any family; family admins use Management\TreeController instead. */
class FamilyTreeController extends Controller
{
    public function __construct(private readonly FamilyTreeManager $trees) {}

    public function store(Request $request, int $family): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $this->trees->create(Family::query()->findOrFail($family), $request->user(), $request);

        return back()->with('status', 'تم إنشاء الشجرة.');
    }

    public function update(Request $request, int $family, int $tree): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $familyModel = Family::query()->findOrFail($family);
        $this->trees->update($familyModel, $familyModel->familyTrees()->whereKey($tree)->firstOrFail(), $request->user(), $request);

        return back()->with('status', 'تم تحديث الشجرة.');
    }
}
