<?php

namespace Database\Seeders;

use App\Models\CommunityEntity;
use App\Models\EntityNarrative;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\NewsPost;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synthetic UI fixtures for design and browser testing (D3 / C6). Local or testing only, explicit opt-in
 * (JUTHOOR_DEMO_FIXTURES=true), never called by DatabaseSeeder. Everything lives in its own clearly labelled family;
 * people stay pending review with unknown living status and content stays in draft, so nothing is published as
 * family history. No real names, contacts or sources. Idempotent by slug and canonical key.
 */
class JuthoorUiDemoSeeder extends Seeder
{
    public const SLUG = 'demo-juthoor';

    public const LABEL = 'بيانات تجريبية اصطناعية';

    /** @var array<string, array{string, string|null}> canonical key => [name, father key] */
    public const PEOPLE = [
        'DEMO-001' => ['الجد التجريبي الأول', null],
        'DEMO-002' => ['الابن التجريبي أ', 'DEMO-001'],
        'DEMO-003' => ['الابن التجريبي ب', 'DEMO-001'],
        'DEMO-004' => ['الحفيد التجريبي أ١', 'DEMO-002'],
        'DEMO-005' => ['الحفيد التجريبي أ٢', 'DEMO-002'],
        'DEMO-006' => ['الحفيد التجريبي ب١', 'DEMO-003'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Synthetic demo fixtures are restricted to local/testing environments.');
        }
        if (! config('seeding.demo_fixtures')) {
            throw new RuntimeException('Synthetic demo fixtures need an explicit opt-in: set JUTHOOR_DEMO_FIXTURES=true.');
        }

        DB::transaction(function (): void {
            $family = Family::query()->firstOrCreate(['slug' => self::SLUG], [
                'name_ar' => 'عائلة تجريبية — '.self::LABEL,
                'name_en' => 'Synthetic demo family',
                'short_description' => self::LABEL.' لاختبار الواجهات فقط؛ ليست تاريخ عائلة حقيقية.',
                'is_active' => true,
            ]);
            $tree = FamilyTree::query()->firstOrCreate(['family_id' => $family->id, 'slug' => 'main'], [
                'name_ar' => 'شجرة تجريبية', 'description' => self::LABEL, 'is_primary' => true, 'is_active' => true,
            ]);
            $source = Source::query()->firstOrCreate(['family_id' => $family->id, 'reference' => 'demo:synthetic-fixtures'], [
                'source_type' => 'note', 'title' => 'مصدر تجريبي — '.self::LABEL,
                'notes' => 'ليس مصدرًا تاريخيًا.', 'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
            ]);

            $people = [];
            foreach (self::PEOPLE as $key => [$name, $fatherKey]) {
                $people[$key] = Person::query()->firstOrCreate(['family_tree_id' => $tree->id, 'canonical_key' => $key], [
                    'first_name_ar' => $name, 'full_name_ar' => $name, 'notes' => self::LABEL,
                    'status' => Person::STATUS_UNRESOLVED, 'is_living' => null,
                    'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
                ]);
                if ($fatherKey !== null) {
                    PersonRelationship::query()->firstOrCreate([
                        'family_tree_id' => $tree->id, 'person_id' => $people[$key]->id,
                        'related_person_id' => $people[$fatherKey]->id, 'relationship_type' => PersonRelationship::TYPE_FATHER,
                    ], ['is_primary' => true, 'source_id' => $source->id, 'verification_status' => Source::VERIFICATION_PENDING_REVIEW]);
                }
            }
            if ($tree->root_person_id === null) {
                $tree->update(['root_person_id' => $people['DEMO-001']->id]);
            }

            $clan = CommunityEntity::query()->firstOrCreate(['family_id' => $family->id, 'slug' => 'demo-clan'], [
                'entity_type' => 'clan', 'name_ar' => 'عشيرة تجريبية', 'summary' => self::LABEL,
                'family_tree_id' => $tree->id, 'publication_status' => CommunityEntity::STATUS_DRAFT,
            ]);
            $fakhd = CommunityEntity::query()->firstOrCreate(['family_id' => $family->id, 'slug' => 'demo-fakhd'], [
                'entity_type' => 'fakhd', 'parent_id' => $clan->id, 'name_ar' => 'فخذ تجريبي', 'summary' => self::LABEL,
                'family_tree_id' => $tree->id, 'publication_status' => CommunityEntity::STATUS_DRAFT,
            ]);

            EntityNarrative::query()->firstOrCreate(['owner_entity_id' => $clan->id, 'title' => '[تجريبي] نظرة عامة'], [
                'section' => 'overview', 'account' => 'primary', 'audience' => 'members',
                'publication_status' => EntityNarrative::STATUS_DRAFT, 'body' => 'نص تجريبي لاختبار تخطيط الصفحة. '.self::LABEL.'.',
            ]);
            foreach ([[$clan, 'news', '[تجريبي] خبر لاختبار البطاقات'], [$fakhd, 'announcement', '[تجريبي] إعلان لاختبار القوائم']] as [$owner, $category, $title]) {
                NewsPost::query()->firstOrCreate(['owner_entity_id' => $owner->id, 'title' => $title], [
                    'category' => $category, 'audience' => 'members', 'publication_status' => NewsPost::STATUS_DRAFT,
                    'summary' => self::LABEL, 'body' => 'محتوى تجريبي غير منشور. '.self::LABEL.'.',
                ]);
            }
        });

        $this->command?->info('Synthetic demo fixtures ready in family ['.self::SLUG.'] (drafts and pending records only).');
    }
}
