<?php

namespace Database\Seeders\Fixtures;

use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\PersonAttribute;
use App\Services\ArabicNameNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synthetic genealogy shapes for tree layout and performance testing: a long single-child ancestral chain, dense
 * branching, large sibling groups, nested founders and some living / pending people that must stay hidden. Local or
 * testing only, explicit opt-in (JUTHOOR_DEMO_FIXTURES=true), never called by DatabaseSeeder, in its own clearly
 * labelled family. Deterministic for a given size (JUTHOOR_TREE_FIXTURE_SIZE, default 1000); a rerun with the same
 * size changes nothing.
 */
class TreeStressFixtureSeeder extends Seeder
{
    public const SLUG = 'demo-tree-stress';

    public const LABEL = 'شجرة اختبار اصطناعية';

    public const CHAIN = 30;

    public function run(?int $size = null): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Synthetic tree fixtures are restricted to local/testing environments.');
        }
        if (! config('seeding.demo_fixtures')) {
            throw new RuntimeException('Synthetic tree fixtures need an explicit opt-in: set JUTHOOR_DEMO_FIXTURES=true.');
        }
        $size = max(self::CHAIN + 10, $size ?? (int) env('JUTHOOR_TREE_FIXTURE_SIZE', 1000));
        $slug = self::SLUG.($size === 1000 ? '' : '-'.$size);
        if (Family::query()->where('slug', $slug)->exists()) {
            return;
        }

        DB::transaction(function () use ($size, $slug): void {
            $family = Family::query()->create(['slug' => $slug, 'name_ar' => 'عائلة اختبار الشجرة ('.$size.') — '.self::LABEL, 'short_description' => self::LABEL.'؛ ليست أنسابًا حقيقية.', 'is_active' => true]);
            $tree = FamilyTree::query()->create(['family_id' => $family->id, 'slug' => 'main', 'name_ar' => self::LABEL, 'description' => self::LABEL, 'is_primary' => true, 'is_active' => true]);
            $normalizer = app(ArabicNameNormalizer::class);
            $now = now();
            mt_srand($size);
            $names = ['سالم', 'محمد', 'أحمد', 'علي', 'حسن', 'خالد', 'عمر', 'يوسف', 'إبراهيم', 'سليمان', 'عبدالله', 'موسى', 'عيسى', 'داود', 'زيد', 'حمد', 'ناصر', 'فهد', 'منصور', 'راشد'];
            $insert = function (string $name, ?int $father, int $index, string $verification = 'verified', ?bool $living = false) use ($tree, $normalizer, $now): int {
                $full = $name.' (اختبار '.$index.')';
                $id = DB::table('people')->insertGetId(['family_tree_id' => $tree->id, 'canonical_key' => 'STRESS-'.$index, 'first_name_ar' => $name, 'full_name_ar' => $full, 'name_search' => $normalizer->normalize($full),
                    'status' => 'historical', 'verification_status' => $verification, 'is_living' => $living, 'notes' => self::LABEL, 'created_at' => $now, 'updated_at' => $now]);
                if ($father) {
                    DB::table('person_relationships')->insert(['family_tree_id' => $tree->id, 'person_id' => $id, 'related_person_id' => $father, 'relationship_type' => 'father', 'is_primary' => true,
                        'verification_status' => $verification === 'pending_review' ? 'pending_review' : 'verified', 'created_at' => $now, 'updated_at' => $now]);
                }

                return $id;
            };
            // A long ancestral chain without branching.
            $index = 1;
            $root = $father = $insert('الجد الأول', null, $index++);
            for ($i = 1; $i < self::CHAIN; $i++) {
                $father = $insert($names[$i % count($names)], $father, $index++, $i % 4 === 0 ? 'sourced' : 'verified');
            }
            $tree->update(['root_person_id' => $root]);
            // Breadth-first branching below the chain: mixed fan-out, some large sibling groups, a few hidden people.
            $queue = [$father];
            $junctions = [];
            while ($index <= $size && $queue !== []) {
                $parent = array_shift($queue);
                $children = mt_rand(1, 100) <= 8 ? mt_rand(8, 14) : mt_rand(1, 4);
                for ($c = 0; $c < $children && $index <= $size; $c++) {
                    $roll = mt_rand(1, 100);
                    $id = $insert($names[mt_rand(0, count($names) - 1)], $parent, $index++, $roll <= 3 ? 'pending_review' : 'verified', $roll > 3 && $roll <= 6 ? true : false);
                    if ($roll > 6) {
                        $queue[] = $id;
                    }
                }
                if ($children >= 3 && count($junctions) < 6) {
                    $junctions[] = $parent;
                }
            }
            // Founders: an approved branch hierarchy on real junctions of this synthetic tree.
            $types = ['fakhd', 'fakhd', 'sub_fakhd', Branch::TYPE_FAMILY_GROUP];
            $attribute = PersonAttribute::query()->whereNull('family_id')->where('key', 'fakhd-founder')->first();
            $clan = CommunityEntity::query()->create(['family_id' => $family->id, 'entity_type' => 'clan', 'name_ar' => 'عشيرة اختبار الشجرة', 'slug' => 'stress-clan', 'publication_status' => 'published', 'family_tree_id' => $tree->id, 'summary' => self::LABEL]);
            foreach (array_slice($junctions, 0, 4) as $n => $founder) {
                $branch = Branch::query()->create(['family_tree_id' => $tree->id, 'root_person_id' => $founder, 'branch_type' => $types[$n],
                    'name_ar' => 'فرع اختبار '.($n + 1), 'slug' => 'stress-'.($n + 1), 'description' => 'وصف تجريبي للفرع '.($n + 1).' — '.self::LABEL, 'membership_mode' => Branch::MEMBERSHIP_DESCENDANTS,
                    'sort_order' => $n, 'is_active' => true, 'verification_status' => 'verified']);
                CommunityEntity::query()->create(['family_id' => $family->id, 'parent_id' => $clan->id, 'entity_type' => 'fakhd', 'name_ar' => 'فخذ اختبار '.($n + 1), 'slug' => 'stress-fakhd-'.($n + 1), 'publication_status' => 'published', 'branch_id' => $branch->id]);
                if ($attribute) {
                    DB::table('person_attribute_assignments')->insertOrIgnore(['person_id' => $founder, 'person_attribute_id' => $attribute->id, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });
    }
}
