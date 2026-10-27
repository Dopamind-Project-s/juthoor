<?php

namespace Database\Seeders\Fixtures;

use App\Models\ArchiveRecord;
use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\CommunityLocation;
use App\Models\Diwan;
use App\Models\EntityAchievement;
use App\Models\EntityNarrative;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use App\Services\Community\CommunityContentService;
use App\Services\Community\MediaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synthetic heritage showcase for UX-P0.4/P0.5 design and browser testing. Local or testing only, explicit opt-in
 * (JUTHOOR_DEMO_FIXTURES=true), never called by DatabaseSeeder, in its own clearly labelled family (never al-zoubi).
 * Unlike JuthoorUiDemoSeeder it publishes content, because public portal pages can only be reviewed with published
 * records; every name, title, image and source carries the «[تجريبي]» label, no coordinates or real addresses are
 * stored, and the generated images are abstract patterns marked as synthetic. Idempotent: a rerun changes nothing.
 */
class HeritageShowcaseFixtureSeeder extends Seeder
{
    public const SLUG = 'demo-heritage';

    public const LABEL = 'بيانات تجريبية اصطناعية';

    /** @var array<string, array{string, string|null, array<string, mixed>}> canonical key => [name, father key, overrides] */
    public const PEOPLE = [
        'HER-001' => ['[تجريبي] الجد الجامع', null, []],
        'HER-002' => ['[تجريبي] مؤسس الفخذ الأول', 'HER-001', []],
        'HER-003' => ['[تجريبي] مؤسس الفخذ الثاني', 'HER-001', []],
        'HER-004' => ['[تجريبي] مؤسس الفرع أ', 'HER-002', []],
        'HER-005' => ['[تجريبي] حفيد أ٢', 'HER-002', []],
        'HER-006' => ['[تجريبي] حفيد ب١', 'HER-003', []],
        'HER-007' => ['[تجريبي] شخص حي', 'HER-004', ['is_living' => true]],
        'HER-008' => ['[تجريبي] سجل قيد المراجعة', 'HER-004', ['verification_status' => Source::VERIFICATION_PENDING_REVIEW]],
    ];

    private User $admin;

    private Source $source;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Synthetic heritage fixtures are restricted to local/testing environments.');
        }
        if (! config('seeding.demo_fixtures')) {
            throw new RuntimeException('Synthetic heritage fixtures need an explicit opt-in: set JUTHOOR_DEMO_FIXTURES=true.');
        }
        if (Family::query()->where('slug', self::SLUG)->exists()) {
            $this->command?->info('Heritage showcase family ['.self::SLUG.'] already exists; nothing changed.');

            return;
        }

        $family = Family::query()->create([
            'slug' => self::SLUG, 'name_ar' => 'عائلة العرض التراثي — '.self::LABEL, 'name_en' => 'Synthetic heritage showcase',
            'short_description' => self::LABEL.' لمراجعة صفحات التراث فقط؛ ليست تاريخ عائلة حقيقية.', 'is_active' => true,
        ]);
        $tree = FamilyTree::query()->create(['family_id' => $family->id, 'slug' => 'main', 'name_ar' => '[تجريبي] الشجرة الجامعة', 'description' => self::LABEL, 'is_primary' => true, 'is_active' => true]);
        $this->admin = User::query()->forceCreate([
            'name' => '[تجريبي] مدير العرض التراثي', 'username' => 'demo-heritage-fixture', 'email' => 'demo-heritage-fixture@example.invalid',
            'password' => Str::random(40), 'is_active' => true,
        ]);
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $this->admin->id, 'role' => 'admin']);
        $this->admin = $this->admin->fresh();
        $this->source = Source::query()->create([
            'family_id' => $family->id, 'reference' => 'demo:heritage-showcase', 'source_type' => 'note', 'title' => '[تجريبي] مصدر اصطناعي للعرض',
            'notes' => 'ليس مصدرًا تاريخيًا.', 'verification_status' => Source::VERIFICATION_SOURCED,
        ]);

        $people = [];
        foreach (self::PEOPLE as $key => [$name, $fatherKey, $overrides]) {
            $people[$key] = Person::query()->create([
                'family_tree_id' => $tree->id, 'canonical_key' => $key, 'first_name_ar' => $name, 'full_name_ar' => $name, 'notes' => self::LABEL,
                'status' => 'historical', 'is_living' => false, 'verification_status' => Source::VERIFICATION_SOURCED, ...$overrides,
            ]);
            if ($fatherKey !== null) {
                PersonRelationship::query()->create([
                    'family_tree_id' => $tree->id, 'person_id' => $people[$key]->id, 'related_person_id' => $people[$fatherKey]->id,
                    'relationship_type' => PersonRelationship::TYPE_FATHER, 'is_primary' => true, 'source_id' => $this->source->id,
                    'verification_status' => $overrides['verification_status'] ?? Source::VERIFICATION_SOURCED,
                ]);
            }
        }
        $tree->update(['root_person_id' => $people['HER-001']->id]);

        $branch = fn (string $slug, string $name, Person $root, string $type) => Branch::query()->create([
            'family_tree_id' => $tree->id, 'slug' => $slug, 'name_ar' => $name, 'root_person_id' => $root->id, 'branch_type' => $type,
            'membership_mode' => 'descendants', 'is_active' => true, 'verification_status' => Source::VERIFICATION_SOURCED,
        ]);
        $entity = fn (string $slug, string $type, string $name, ?CommunityEntity $parent, ?Branch $anchor = null, array $extra = []) => CommunityEntity::query()->create([
            'family_id' => $family->id, 'parent_id' => $parent?->id, 'entity_type' => $type, 'slug' => $slug, 'name_ar' => $name,
            'summary' => self::LABEL.' — صفحة لمراجعة التصميم.', 'family_tree_id' => $tree->id, 'branch_id' => $anchor?->id,
            'publication_status' => CommunityEntity::STATUS_PUBLISHED, 'reviewed_at' => now(), ...$extra,
        ]);
        $clan = $entity('clan', 'clan', '[تجريبي] العشيرة الجامعة', null, null, ['alternate_names' => ['[تجريبي] اسم بديل للعشيرة']]);
        $fakhdA = $entity('fakhd-a', 'fakhd', '[تجريبي] الفخذ الأول', $clan, $branch('fakhd-a', '[تجريبي] فرع الفخذ الأول', $people['HER-002'], 'fakhd'));
        $fakhdB = $entity('fakhd-b', 'fakhd', '[تجريبي] الفخذ الثاني', $clan, $branch('fakhd-b', '[تجريبي] فرع الفخذ الثاني', $people['HER-003'], 'fakhd'));
        $subA = $entity('sub-a', 'sub_fakhd', '[تجريبي] الفرع أ', $fakhdA, $branch('sub-a', '[تجريبي] فرع أ', $people['HER-004'], 'sub_fakhd'));
        $entity('fakhd-c', 'fakhd', '[تجريبي] فخذ دون شجرة منشورة', $clan);

        $this->content(EntityNarrative::class, $clan, ['section' => 'overview', 'account' => 'primary', 'title' => '[تجريبي] نظرة عامة على العشيرة', 'body' => 'نص اصطناعي لمراجعة التخطيط. '.self::LABEL.'.']);
        $origin = $this->content(EntityNarrative::class, $clan, ['section' => 'origins', 'account' => 'primary', 'title' => '[تجريبي] رواية الأصل المعروضة', 'body' => 'رواية اصطناعية للأصل. '.self::LABEL.'.', 'period_label' => '[تجريبي] حقبة اصطناعية', 'start_year' => 1700]);
        $this->content(EntityNarrative::class, $clan, ['section' => 'origins', 'account' => 'alternative', 'title' => '[تجريبي] رواية بديلة للأصل', 'body' => 'رواية اصطناعية مختلفة تبقى ظاهرة. '.self::LABEL.'.'], ['verification_status' => 'unresolved']);
        $this->content(EntityNarrative::class, $fakhdA, ['section' => 'migration', 'account' => 'primary', 'title' => '[تجريبي] انتقال الفخذ الأول', 'body' => 'محطة اصطناعية. '.self::LABEL.'.', 'start_year' => 1850, 'end_year' => 1870]);
        $origin->citations()->create(['source_id' => $this->source->id, 'locator' => '[تجريبي] ص ١', 'verification_status' => Source::VERIFICATION_SOURCED]);

        $this->content(CommunityLocation::class, $clan, ['name' => '[تجريبي] منطقة أصل', 'era' => 'historical', 'kind' => 'origin']);
        $this->content(CommunityLocation::class, $fakhdA, ['name' => '[تجريبي] منطقة استقرار مشتركة', 'era' => 'current', 'kind' => 'settlement'], ['subjects' => [$fakhdA->id, $fakhdB->id]]);
        $this->content(CommunityLocation::class, $subA, ['name' => '[تجريبي] منطقة تجمع', 'era' => 'current', 'kind' => 'gathering']);

        $sharedDiwan = $this->content(Diwan::class, $fakhdA, ['name' => '[تجريبي] ديوان مشترك', 'kind' => 'diwan', 'description' => 'ديوان اصطناعي مشترك بين فخذين. '.self::LABEL.'.', 'city' => '[تجريبي] مدينة', 'visit_instructions' => '[تجريبي] تعليمات زيارة'], ['subjects' => [$fakhdA->id, $fakhdB->id]]);
        $this->content(Diwan::class, $fakhdB, ['name' => '[تجريبي] مضافة في بيت خاص', 'kind' => 'madafa', 'city' => '[تجريبي] بلدة', 'public_address' => '[تجريبي] عنوان بموافقة', 'is_private_residence' => true, 'residence_consent' => true, 'contact_name' => '[تجريبي] جهة اتصال دون موافقة', 'contact_phone' => '0000000000', 'contact_consent' => false]);

        $this->content(EntityAchievement::class, $fakhdA, ['person_id' => $people['HER-002']->id, 'kind' => 'position', 'category' => 'social', 'title' => '[تجريبي] منصب اجتماعي']);
        $this->content(EntityAchievement::class, $subA, ['person_id' => $people['HER-004']->id, 'kind' => 'achievement', 'category' => 'scholar', 'title' => '[تجريبي] إنجاز علمي']);
        $this->content(EntityAchievement::class, $subA, ['person_id' => $people['HER-007']->id, 'kind' => 'achievement', 'category' => 'professional', 'title' => '[تجريبي] إنجاز لشخص حي']);

        $this->content(ArchiveRecord::class, $clan, ['title' => '[تجريبي] مشجرة اصطناعية', 'category' => 'genealogy_chart', 'rights_status' => 'owned', 'start_year' => 1900]);
        $this->content(ArchiveRecord::class, $fakhdA, ['title' => '[تجريبي] رواية شفوية اصطناعية', 'category' => 'oral_history', 'rights_status' => 'owned']);

        if (extension_loaded('gd')) {
            $cover = $this->image($clan, 'cover', '[تجريبي] غلاف اصطناعي — ليس صورة للعائلة', [19, 61, 50]);
            $clan->update(['cover_media_id' => $cover->id]);
            $diwanImage = $this->image($fakhdA, 'diwan', '[تجريبي] صورة اصطناعية للديوان', [178, 140, 84]);
            app(MediaService::class)->attach($this->admin, $sharedDiwan, $diwanImage, '[تجريبي] صورة اصطناعية');
        }

        $this->command?->info('Heritage showcase ready in family ['.self::SLUG.'] (synthetic, labelled, local/testing only).');
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $governance
     */
    private function content(string $model, CommunityEntity $owner, array $fields, array $governance = []): Model
    {
        return app(CommunityContentService::class)->save($this->admin, $owner, new $model, $fields, [
            'publication_status' => 'published', 'verification_status' => Source::VERIFICATION_SOURCED, 'audience' => 'public', 'subjects' => [], 'audiences' => [], ...$governance,
        ]);
    }

    /** An abstract striped PNG (no photograph, no text) marked as synthetic in its title, alt text and provenance. */
    private function image(CommunityEntity $owner, string $name, string $title, array $rgb): MediaItem
    {
        $canvas = imagecreatetruecolor(960, 540);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$rgb));
        $stripe = imagecolorallocatealpha($canvas, 246, 244, 238, 100);
        for ($x = -540; $x < 960; $x += 60) {
            imagefilledpolygon($canvas, [$x, 540, $x + 30, 540, $x + 570, 0, $x + 540, 0], $stripe);
        }
        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();

        return app(MediaService::class)->upload($this->admin, $owner, UploadedFile::fake()->createWithContent($name.'.png', $bytes), [
            'title' => $title, 'alt_text' => 'نمط زخرفي اصطناعي للاختبار', 'description' => self::LABEL, 'provenance' => self::LABEL, 'rights_status' => 'owned',
        ], ['publication_status' => 'published', 'verification_status' => Source::VERIFICATION_SOURCED, 'audience' => 'public', 'subjects' => [], 'audiences' => []]);
    }
}
