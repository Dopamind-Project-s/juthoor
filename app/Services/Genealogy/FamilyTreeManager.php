<?php

namespace App\Services\Genealogy;

use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creating and editing a family's trees, shared by the site-admin workspace (super admins) and the
 * family-scoped workspace (family admins). Callers authorize; this class validates and audits.
 */
class FamilyTreeManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly TreeRootService $roots) {}

    public function create(Family $family, User $user, Request $request): FamilyTree
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique('family_trees')->where('family_id', $family->id)],
            'is_primary' => ['nullable', 'boolean'],
        ] + TreeRootService::rules());

        return DB::transaction(function () use ($data, $family, $user, $request): FamilyTree {
            Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            $tree = $family->familyTrees()->create([
                'name_ar' => $data['name_ar'], 'slug' => $data['slug'],
                'is_primary' => $request->boolean('is_primary'), 'is_active' => true,
            ]);
            $this->audit->record($user, $family, $tree, 'created', null, $this->audit->snapshot($tree));
            $this->roots->configure($tree, $user, $data);

            return $tree;
        });
    }

    public function update(Family $family, FamilyTree $tree, User $user, Request $request): void
    {
        if ($tree->is_primary && ! $request->boolean('is_primary')) {
            throw ValidationException::withMessages(['is_primary' => 'اجعل شجرة أخرى أساسية أولًا.']);
        }
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'], 'is_primary' => ['nullable', 'boolean'],
        ] + TreeRootService::rules());
        DB::transaction(function () use ($data, $family, $tree, $user, $request): void {
            Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            FamilyTree::query()->whereKey($tree->id)->lockForUpdate()->firstOrFail();
            $tree->refresh();
            $before = $this->audit->snapshot($tree);
            $tree->update([
                'name_ar' => $data['name_ar'], 'is_active' => $request->boolean('is_active'),
                'is_primary' => $request->boolean('is_primary'),
            ]);
            $this->audit->record($user, $family, $tree, 'updated', $before, $this->audit->snapshot($tree));
            $this->roots->configure($tree, $user, $data);
        });
    }
}
