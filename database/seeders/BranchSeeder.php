<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Family;
use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Creates the documented branches when missing (SD-05). An existing branch keeps its administrator-edited name, parent,
 * type, order, state and founder.
 */
class BranchSeeder extends Seeder
{
    /** @return array<string, array{name_ar: string, parent: string|null, sort_order: int}> */
    public static function definitions(): array
    {
        return [
            'bakr' => ['name_ar' => 'بكر', 'parent' => null, 'sort_order' => 0],
            'eid' => ['name_ar' => 'عيد', 'parent' => 'bakr', 'sort_order' => 0],
            'khamis' => ['name_ar' => 'خميس', 'parent' => 'bakr', 'sort_order' => 1],
            'al-rahaymeh' => ['name_ar' => 'الرحايمة', 'parent' => null, 'sort_order' => 1],
            'al-awtalat' => ['name_ar' => 'العوتلات', 'parent' => null, 'sort_order' => 2],
            'al-qawasmeh' => ['name_ar' => 'القواسمة', 'parent' => null, 'sort_order' => 3],
        ];
    }

    public function run(): void
    {
        $tree = Family::query()->where('slug', FamilySeeder::SLUG)->sole()
            ->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->sole();
        $ids = [];

        foreach (self::definitions() as $slug => $definition) {
            $branch = Branch::query()->firstOrCreate(['family_tree_id' => $tree->id, 'slug' => $slug], [
                'parent_branch_id' => $definition['parent'] ? $ids[$definition['parent']] : null,
                'branch_type' => Branch::TYPE_FAMILY_GROUP,
                'name_ar' => $definition['name_ar'],
                'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS,
                'sort_order' => $definition['sort_order'],
                'description' => 'تسمية موثقة؛ مؤسس الفرع ينتظر المطابقة مع الرسم النسبي.',
                'is_active' => false,
                'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
            ]);
            $ids[$slug] = $branch->id;
        }
    }
}
