<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FamilyTreeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, int $family): RedirectResponse
    {
        $familyModel = Family::query()->findOrFail($family);
        Gate::authorize('update', $familyModel);
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique('family_trees')->where('family_id', $familyModel->id)],
            'is_primary' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($data, $familyModel, $request): void {
            Family::query()->whereKey($familyModel->id)->lockForUpdate()->firstOrFail();
            $tree = $familyModel->familyTrees()->create([
                'name_ar' => $data['name_ar'], 'slug' => $data['slug'],
                'is_primary' => $request->boolean('is_primary'), 'is_active' => true,
            ]);
            $this->audit->record($request->user(), $familyModel, $tree, 'created', null, $this->audit->snapshot($tree));
        });

        return back()->with('status', 'تم إنشاء الشجرة.');
    }

    public function update(Request $request, int $family, int $tree): RedirectResponse
    {
        $familyModel = Family::query()->findOrFail($family);
        Gate::authorize('update', $familyModel);
        $treeModel = $familyModel->familyTrees()->whereKey($tree)->firstOrFail();
        if ($treeModel->is_primary && ! $request->boolean('is_primary')) {
            throw ValidationException::withMessages(['is_primary' => 'اجعل شجرة أخرى أساسية أولًا.']);
        }
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'], 'is_primary' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($data, $familyModel, $treeModel, $request): void {
            Family::query()->whereKey($familyModel->id)->lockForUpdate()->firstOrFail();
            $before = $this->audit->snapshot($treeModel);
            $treeModel->update([
                'name_ar' => $data['name_ar'], 'is_active' => $request->boolean('is_active'),
                'is_primary' => $request->boolean('is_primary'),
            ]);
            $this->audit->record($request->user(), $familyModel, $treeModel, 'updated', $before, $this->audit->snapshot($treeModel));
        });

        return back()->with('status', 'تم تحديث الشجرة.');
    }
}
