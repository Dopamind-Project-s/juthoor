<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TreeController extends Controller
{
    public function update(Request $request, string $family, int $treeId, AuditLogger $audit): RedirectResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('update', $familyModel);
        $treeModel = $familyModel->familyTrees()->findOrFail($treeId);
        $data = $request->validate(['root_person_id' => ['nullable', 'integer']]);
        try {
            DB::transaction(function () use ($request, $familyModel, $treeModel, $data, $audit): void {
                FamilyTree::query()->whereKey($treeModel->id)->lockForUpdate()->firstOrFail();
                $treeModel->refresh();
                $before = $audit->snapshot($treeModel);
                $treeModel->update(['root_person_id' => $data['root_person_id'] ?? null]);
                $audit->record($request->user(), $familyModel, $treeModel, 'updated', $before, $audit->snapshot($treeModel));
            });
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['root_person_id' => 'اختر جذرًا من هذه الشجرة دون أب موثق.']);
        }

        return back()->with('status', 'تم حفظ جذر الشجرة.');
    }
}
