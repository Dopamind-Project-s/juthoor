<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Family;
use App\Models\Source;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $tree = Family::query()->where('slug', FamilySeeder::SLUG)->sole()
            ->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->sole();
        $bakr = $this->storeBranch($tree->id, null, 'بكر', 'bakr', 0);

        $this->storeBranch($tree->id, $bakr->id, 'عيد', 'eid', 0);
        $this->storeBranch($tree->id, $bakr->id, 'خميس', 'khamis', 1);
        $this->storeBranch($tree->id, null, 'الرحايمة', 'al-rahaymeh', 1);
        $this->storeBranch($tree->id, null, 'العوتلات', 'al-awtalat', 2);
        $this->storeBranch($tree->id, null, 'القواسمة', 'al-qawasmeh', 3);
    }

    private function storeBranch(int $treeId, ?int $parentBranchId, string $name, string $slug, int $sortOrder): Branch
    {
        $branch = Branch::query()->firstOrNew([
            'family_tree_id' => $treeId,
            'slug' => $slug,
        ]);
        $branch->fill([
            'parent_branch_id' => $parentBranchId,
            'branch_type' => Branch::TYPE_FAMILY_GROUP,
            'name_ar' => $name,
            'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS,
            'sort_order' => $sortOrder,
        ]);

        if (! $branch->exists) {
            $branch->fill([
                'description' => 'تسمية موثقة؛ مؤسس الفرع ينتظر المطابقة مع الرسم النسبي.',
                'is_active' => false,
                'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
            ]);
        }

        $branch->save();

        return $branch;
    }
}
