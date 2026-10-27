<?php

use App\Models\CommunityEntity;
use App\Models\CommunityLocation;
use App\Models\Diwan;
use App\Models\EntityAchievement;
use App\Models\Family;
use App\Models\Person;
use App\Models\User;
use App\Services\Community\MediaService;
use App\Services\Portal\PortalSettings;
use Database\Seeders\HeritageShowcaseFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const SHOWCASE = HeritageShowcaseFixtureSeeder::SLUG;

beforeEach(function () {
    Storage::fake('media');
    config(['seeding.demo_fixtures' => true]);
    $this->seed(HeritageShowcaseFixtureSeeder::class);
    $this->family = Family::query()->where('slug', SHOWCASE)->sole();
    $this->admin = User::query()->where('username', 'demo-heritage-fixture')->sole();
});

function showcaseEntity(string $slug): CommunityEntity
{
    return CommunityEntity::query()->where('family_id', Family::query()->where('slug', SHOWCASE)->value('id'))->where('slug', $slug)->sole();
}

function showcasePerson(string $key): Person
{
    return Person::query()->where('canonical_key', $key)->sole();
}

/** @return array<int, string> data-record ids rendered on the page */
function showcaseRecords(string $html): array
{
    preg_match_all('/data-record="([a-z]+-\d+)"/', $html, $matches);

    return $matches[1];
}

/** A tiny metadata-free PNG; enough for the media pipeline without GD. */
function showcasePng(string $salt): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1A\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress("\0".substr(md5($salt, true), 0, 3))).$chunk('IEND', '');
}

test('the showcase fixture is opt-in, labelled, isolated and idempotent', function () {
    $counts = fn () => [Family::query()->count(), Person::query()->count(), CommunityEntity::query()->count(), Diwan::query()->count(), User::query()->count()];
    $before = $counts();
    $this->seed(HeritageShowcaseFixtureSeeder::class);

    expect($counts())->toBe($before)
        ->and($this->family->name_ar)->toContain(HeritageShowcaseFixtureSeeder::LABEL)
        ->and(Person::query()->whereIn('family_tree_id', $this->family->familyTrees()->select('id'))->get()->every(fn (Person $person) => str_starts_with($person->full_name_ar, '[تجريبي]')))->toBeTrue()
        ->and(CommunityEntity::query()->where('family_id', '!=', $this->family->id)->count())->toBe(0)
        ->and(Diwan::query()->whereNotNull('latitude')->count())->toBe(0);

    config(['seeding.demo_fixtures' => false]);
    expect(fn () => app(HeritageShowcaseFixtureSeeder::class)->run())->toThrow(RuntimeException::class, 'explicit opt-in');
    config(['seeding.demo_fixtures' => true]);
    $this->app->instance('env', 'production');
    expect(fn () => app(HeritageShowcaseFixtureSeeder::class)->run())->toThrow(RuntimeException::class, 'local/testing');
});

test('the family landing uses the shared frame with geography and gallery sections in saved order', function () {
    $page = $this->get(route('portal.show', SHOWCASE))->assertOk()
        ->assertSee('design-system.css')->assertSee('id="site-main"', false)->assertSee('--j-brand:', false)
        ->assertSee('[تجريبي] منطقة أصل')->assertSee('[تجريبي] منطقة استقرار مشتركة');

    expect(array_column(app(PortalSettings::class)->published($this->family)['sections'], 'key'))->toContain('geography', 'gallery');
    expect(substr_count($page->getContent(), '[تجريبي] منطقة استقرار مشتركة'))->toBe(1);
});

test('the directory lists public levels in hierarchy order with founders from published branches only', function () {
    $draft = CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => showcaseEntity('clan')->id, 'entity_type' => 'fakhd', 'slug' => 'draft', 'name_ar' => '[تجريبي] مسودة مخفية', 'publication_status' => 'draft']);

    $page = $this->get(route('community.entities.index', SHOWCASE))->assertOk()
        ->assertSeeInOrder(['data-entity="clan"', 'data-entity="fakhd-a"', 'data-entity="sub-a"', 'data-entity="fakhd-b"'], false)
        ->assertSee('المؤسس: [تجريبي] مؤسس الفخذ الأول')->assertDontSee($draft->name_ar)
        ->assertSee('data-directory-total>5<', false);
    expect(substr_count($page->getContent(), 'data-founder'))->toBe(3);

    $this->get(route('community.entities.index', [SHOWCASE, 'q' => 'مؤسس الفرع']))->assertOk()
        ->assertSee('data-entity="sub-a"', false)->assertDontSee('data-entity="fakhd-b"', false);
    $this->get(route('community.entities.index', [SHOWCASE, 'type' => 'sub_fakhd']))->assertSee('data-entity="sub-a"', false)->assertDontSee('data-entity="clan"', false);
    $this->get(route('community.entities.index', [SHOWCASE, 'within' => showcaseEntity('fakhd-a')->id]))
        ->assertSee('data-entity="sub-a"', false)->assertDontSee('data-entity="fakhd-b"', false);
});

test('one entity portal serves every level with breadcrumb, tabs, founder card and an on-demand tree embed', function () {
    $clan = $this->get(route('community.entities.show', [SHOWCASE, 'clan']))->assertOk()
        ->assertSee('role="tablist"', false)->assertSee('data-tab="tree"', false)->assertSee('data-tab="sources"', false)
        ->assertDontSee('data-founder-card', false);

    $sub = $this->get(route('community.entities.show', [SHOWCASE, 'sub-a']))->assertOk()
        ->assertSeeInOrder(['[تجريبي] العشيرة الجامعة', '[تجريبي] الفخذ الأول', '[تجريبي] الفرع أ'])
        ->assertSee('[تجريبي] مؤسس الفرع أ')->assertSee('data-verification="sourced"', false)->assertSee('data-embed-src', false)->assertDontSee('<iframe', false);
    preg_match('/data-embed-src="([^"]+)"/', $sub->getContent(), $embed);
    expect(html_entity_decode($embed[1]))->toContain('embed=1')->not->toContain('private');

    $this->get(html_entity_decode($embed[1]))->assertOk()->assertSee('genealogy-page is-embed', false);
    expect($clan->getContent())->toContain('data-tab="overview"');
});

test('the scope switch drives every section: roll-up, direct-only and sibling isolation', function () {
    $gathering = CommunityLocation::query()->where('name', '[تجريبي] منطقة تجمع')->sole();
    $subAchievement = EntityAchievement::query()->where('title', '[تجريبي] إنجاز علمي')->sole();

    expect(showcaseRecords($this->get(route('community.entities.show', [SHOWCASE, 'clan']))->getContent()))->toContain("locations-{$gathering->id}", "achievements-{$subAchievement->id}")
        ->and(showcaseRecords($this->get(route('community.entities.show', [SHOWCASE, 'clan', 'scope' => 'direct']))->getContent()))->not->toContain("locations-{$gathering->id}", "achievements-{$subAchievement->id}")
        ->and(showcaseRecords($this->get(route('community.entities.show', [SHOWCASE, 'fakhd-b']))->getContent()))->not->toContain("achievements-{$subAchievement->id}");
});

test('a shared diwan is one record: shown once in roll-ups and on both entities, with one detail page', function () {
    $shared = Diwan::query()->where('name', '[تجريبي] ديوان مشترك')->sole();
    $clanRecords = array_count_values(showcaseRecords($this->get(route('community.entities.show', [SHOWCASE, 'clan']))->getContent()));

    expect($clanRecords["diwans-{$shared->id}"])->toBe(1)
        ->and(showcaseRecords($this->get(route('community.entities.show', [SHOWCASE, 'fakhd-b']))->getContent()))->toContain("diwans-{$shared->id}");

    $detail = $this->get(route('community.diwans.show', [SHOWCASE, $shared->id]))->assertOk()
        ->assertSee('[تجريبي] الفخذ الأول')->assertSee('[تجريبي] الفخذ الثاني')->assertSee('[تجريبي] تعليمات زيارة');
    expect(substr_count($detail->getContent(), 'data-related-entity'))->toBe(2);
});

test('diwan detail pages respect residence privacy, publication and family isolation', function () {
    $private = Diwan::query()->where('name', '[تجريبي] مضافة في بيت خاص')->sole();
    $this->get(route('community.diwans.show', [SHOWCASE, $private->id]))->assertOk()
        ->assertSee('[تجريبي] عنوان بموافقة')->assertDontSee('0000000000')->assertDontSee('data-diwan-contact', false);
    // Consent withdrawn after publication: the residence keeps its page but no address, map or directions.
    $private->forceFill(['residence_consent' => false])->save();
    $this->get(route('community.diwans.show', [SHOWCASE, $private->id]))->assertOk()
        ->assertDontSee('[تجريبي] عنوان بموافقة')->assertDontSee('data-directions', false)->assertDontSee('maps', false);

    $private->forceFill(['publication_status' => 'draft'])->save();
    $this->get(route('community.diwans.show', [SHOWCASE, $private->id]))->assertNotFound();

    Family::query()->create(['name_ar' => 'عائلة أخرى', 'slug' => 'other', 'is_active' => true]);
    $shared = Diwan::query()->where('name', '[تجريبي] ديوان مشترك')->sole();
    $this->get(route('community.diwans.show', ['other', $shared->id]))->assertNotFound();
    $this->get(route('community.entities.show', ['other', 'clan']))->assertNotFound();
});

test('restricted media never reaches the diwan page or the entity gallery', function () {
    $shared = Diwan::query()->where('name', '[تجريبي] ديوان مشترك')->sole();
    $media = app(MediaService::class);
    $governance = ['verification_status' => 'sourced', 'subjects' => [], 'audiences' => []];
    $open = $media->upload($this->admin, showcaseEntity('fakhd-a'), UploadedFile::fake()->createWithContent('open.png', showcasePng('open')), ['title' => '[تجريبي] صورة عامة', 'alt_text' => 'نمط', 'rights_status' => 'owned'], [...$governance, 'publication_status' => 'published', 'audience' => 'public']);
    $members = $media->upload($this->admin, showcaseEntity('fakhd-a'), UploadedFile::fake()->createWithContent('members.png', showcasePng('members')), ['title' => '[تجريبي] صورة للأعضاء', 'alt_text' => 'نمط', 'rights_status' => 'owned'], [...$governance, 'publication_status' => 'published', 'audience' => 'members']);
    $unknown = $media->upload($this->admin, showcaseEntity('fakhd-a'), UploadedFile::fake()->createWithContent('rights.png', showcasePng('rights')), ['title' => '[تجريبي] حقوق غير محددة', 'rights_status' => 'unknown'], [...$governance, 'publication_status' => 'draft', 'audience' => 'public']);
    foreach ([$open, $members, $unknown] as $item) {
        $media->attach($this->admin, $shared, $item, null);
    }

    $detail = $this->get(route('community.diwans.show', [SHOWCASE, $shared->id]))->assertOk()->assertSee($open->uuid);
    $portal = $this->get(route('community.entities.show', [SHOWCASE, 'fakhd-a']))->assertOk();
    foreach ([$detail, $portal] as $page) {
        $page->assertDontSee($members->uuid)->assertDontSee($unknown->uuid);
    }
    $this->get(route('media.show', [$members->uuid, 'display']))->assertNotFound();
    // Family members see member files; drafts stay with the entity's publishers.
    $this->actingAs($this->admin)->get(route('community.diwans.show', [SHOWCASE, $shared->id]))->assertSee($members->uuid);
});

test('history keeps the alternative account visible and dates only what was recorded', function () {
    $this->get(route('community.entities.show', [SHOWCASE, 'clan']))->assertOk()
        ->assertSeeInOrder(['[تجريبي] رواية الأصل المعروضة', '[تجريبي] رواية بديلة للأصل'])
        ->assertSee('رواية بديلة')->assertSee('محل خلاف')->assertSee('data-alternative', false)
        ->assertSee('1700')->assertSee('1850')->assertSee('[تجريبي] مصدر اصطناعي للعرض');
});

test('notable people link only publicly visible persons and keep the non-exhaustive disclaimer', function () {
    $page = $this->get(route('community.entities.show', [SHOWCASE, 'sub-a']))->assertOk()
        ->assertSee('لا تعني القائمة حصر')->assertSee('[تجريبي] إنجاز علمي');
    $html = $page->getContent();
    $founder = showcasePerson('HER-004');
    $living = showcasePerson('HER-007');

    expect($html)->toContain(route('genealogy.person', [SHOWCASE, $founder->id]))
        ->not->toContain(route('genealogy.person', [SHOWCASE, $living->id]));
    // A living person's entry stays with the family: hidden from visitors, listed for members without a public profile link.
    $page->assertDontSee('[تجريبي] إنجاز لشخص حي');
    $this->actingAs($this->admin)->get(route('community.entities.show', [SHOWCASE, 'sub-a']))->assertSee('[تجريبي] إنجاز لشخص حي');
});

test('entity, directory and diwan pages share the family frame, branding tokens and navigation state', function () {
    $diwan = Diwan::query()->where('name', '[تجريبي] ديوان مشترك')->sole();
    foreach ([route('community.entities.show', [SHOWCASE, 'fakhd-a']), route('community.entities.index', SHOWCASE), route('community.diwans.show', [SHOWCASE, $diwan->id])] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html, $url)->toContain('--j-brand:')->toContain('portal-header')->toContain('aria-current="page"')->toContain('id="site-main"');
    }
});

test('existing public routes still answer for the showcase family', function () {
    foreach ([
        route('portal.show', SHOWCASE), route('community.entities.index', SHOWCASE), route('community.entities.show', [SHOWCASE, 'fakhd-c']),
        route('genealogy.tree', [SHOWCASE, 'tree' => 'main']), route('genealogy.branch', [SHOWCASE, 'fakhd-a', 'tree' => 'main']),
        route('directory.index', SHOWCASE), route('community.entities.news', [SHOWCASE, 'clan']),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

test('administration offers the new portal sections, cover selection and a preview of the new entity page', function () {
    $this->admin->forceFill(['email_verified_at' => now()])->save();
    $clan = showcaseEntity('clan');

    $this->actingAs($this->admin)->get(route('management.portal.edit', SHOWCASE))->assertOk()->assertSee('المناطق والانتشار')->assertSee('معرض الصور');
    $this->actingAs($this->admin)->get(route('management.entities.edit', [SHOWCASE, $clan->id]))->assertOk()
        ->assertSee('name="cover_media_id"', false)->assertSee(route('community.entities.show', [SHOWCASE, 'clan']), false);

    $clan->forceFill(['publication_status' => 'draft'])->save();
    $this->get(route('community.entities.show', [SHOWCASE, 'clan']))->assertNotFound();
    $this->actingAs($this->admin)->get(route('community.entities.show', [SHOWCASE, 'clan', 'preview' => 1]))->assertOk()
        ->assertSee('data-entity-status', false)->assertSee('portal-preview-bar', false);
});
