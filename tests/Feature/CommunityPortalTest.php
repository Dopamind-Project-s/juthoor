<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Citation;
use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\CommunityLocation;
use App\Models\ContentCitation;
use App\Models\CrossFamilyLink;
use App\Models\Diwan;
use App\Models\EntityAchievement;
use App\Models\EntityNarrative;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use App\Services\Community\CommunityContentModules;
use App\Services\Community\CommunityContentService;
use App\Services\Genealogy\PersonMergeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Tree: قاسم → محمد → (عمر, حمدان, سالم [living], مجهول [pending record and edge]).
 * Branches: محمد (published), عمر (published), حمدان (pending). Entities: clan (tree) → fakhd F (محمد) → S (عمر), T (حمدان);
 * clan → G (community only). Every entity is published.
 */
beforeEach(function () {
    $this->family = Family::query()->create(['name_ar' => 'عائلة زين', 'slug' => 'zain']);
    $this->tree = $this->family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $person = fn (string $name, array $extra = []) => $this->tree->people()->create(['first_name_ar' => $name, 'full_name_ar' => $name.' الزين', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false, ...$extra]);
    $edge = fn (Person $child, Person $father, string $status = 'verified') => $this->tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => $status]);
    $this->root = $person('قاسم');
    $this->tree->update(['root_person_id' => $this->root->id]);
    $this->mohammad = $person('محمد');
    $edge($this->mohammad, $this->root);
    $this->omar = $person('عمر');
    $edge($this->omar, $this->mohammad);
    $this->hamdan = $person('حمدان');
    $edge($this->hamdan, $this->mohammad);
    $this->living = $person('سالم', ['is_living' => true]);
    $edge($this->living, $this->mohammad);
    $this->pending = $person('مجهول', ['verification_status' => 'pending_review']);
    $edge($this->pending, $this->mohammad, 'pending_review');
    $branch = fn (string $slug, Person $root, string $status = 'verified') => $this->tree->branches()->create(['name_ar' => 'فرع '.$root->first_name_ar, 'slug' => $slug, 'root_person_id' => $root->id, 'branch_type' => 'fakhd', 'membership_mode' => 'descendants', 'is_active' => true, 'verification_status' => $status]);
    $this->branchM = $branch('mohammad', $this->mohammad);
    $this->branchO = $branch('omar', $this->omar);
    $this->branchH = $branch('hamdan', $this->hamdan, 'pending_review');
    $entity = fn (string $slug, string $type, ?CommunityEntity $parent, array $extra = []) => CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $parent?->id, 'entity_type' => $type, 'name_ar' => 'كيان '.$slug, 'slug' => $slug, 'publication_status' => 'published', ...$extra]);
    $this->clan = $entity('clan', 'clan', null, ['family_tree_id' => $this->tree->id]);
    $this->fakhd = $entity('f', 'fakhd', $this->clan, ['branch_id' => $this->branchM->id, 'family_tree_id' => $this->tree->id]);
    $this->sub = $entity('s', 'sub_fakhd', $this->fakhd, ['branch_id' => $this->branchO->id, 'family_tree_id' => $this->tree->id]);
    $this->hidden = $entity('t', 'sub_fakhd', $this->fakhd, ['branch_id' => $this->branchH->id, 'family_tree_id' => $this->tree->id]);
    $this->sibling = $entity('g', 'fakhd', $this->clan);
    foreach (['admin', 'editor', 'reader'] as $role) {
        $this->{$role} = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $this->{$role}->id, 'role' => $role]);
        $this->{$role} = $this->{$role}->fresh();
    }
    $this->source = $this->family->sources()->create(['source_type' => 'book', 'title' => 'كتاب أنساب العشيرة', 'verification_status' => 'sourced']);
});

/**
 * Creates content through the service as the family administrator.
 *
 * @param  class-string<Model>  $model
 */
function portalContent(string $model, CommunityEntity $owner, array $fields, array $governance = []): Model
{
    // The owner family's administrator (other families' admins may exist; no row order is assumed).
    $admin = User::query()->whereHas('familyMemberships', fn ($q) => $q->where('role', 'admin')->where('family_id', $owner->family_id))->orderBy('id')->firstOrFail();

    return app(CommunityContentService::class)->save($admin, $owner, new $model, $fields, [
        'publication_status' => 'published', 'verification_status' => 'sourced', 'audience' => 'public', 'subjects' => [], 'audiences' => [], ...$governance,
    ]);
}

function portalCite(Model $record, Source $source, string $status = 'sourced', ?string $locator = 'ص ١٢'): ContentCitation
{
    return $record->citations()->create(['source_id' => $source->id, 'locator' => $locator, 'verification_status' => $status]);
}

/** @return array<int, string> data-record ids rendered on the page */
function portalRecords(string $html): array
{
    preg_match_all('/data-record="([a-z]+-\d+)"/', $html, $matches);

    return $matches[1];
}

/** @return array<string, mixed> */
function portalForm(array $fields, array $governance = []): array
{
    return [...$fields, 'publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public', ...$governance];
}

test('historical narratives keep competing accounts, evidence status and audience apart', function () {
    $primary = portalContent(EntityNarrative::class, $this->fakhd, ['section' => 'origins', 'account' => 'primary', 'title' => 'أصل الفخذ', 'body' => 'رواية الأصل الأولى.']);
    $alternative = portalContent(EntityNarrative::class, $this->fakhd, ['section' => 'origins', 'account' => 'alternative', 'title' => 'رواية أخرى للأصل', 'body' => 'رواية مختلفة محفوظة.'], ['verification_status' => 'unresolved']);
    portalContent(EntityNarrative::class, $this->fakhd, ['section' => 'migration', 'account' => 'primary', 'title' => 'مسودة هجرة', 'body' => 'لم تُنشر.'], ['publication_status' => 'draft']);
    portalContent(EntityNarrative::class, $this->fakhd, ['section' => 'milestone', 'account' => 'primary', 'title' => 'محطة للأعضاء', 'body' => 'خاص بالعائلة.'], ['audience' => 'members']);
    portalCite($primary, $this->source);
    portalCite($primary, $this->family->sources()->create(['source_type' => 'oral', 'title' => 'رواية شفوية غير معتمدة', 'verification_status' => 'pending_review']), 'pending_review');
    portalCite($alternative, $this->source, 'sourced', 'ص ٤٠');

    $page = $this->get(route('community.entities.show', ['zain', 'f']))->assertOk()
        ->assertSeeInOrder(['أصل الفخذ', 'رواية أخرى للأصل', 'رواية بديلة'])->assertSee('محل خلاف')
        ->assertSee('كتاب أنساب العشيرة')->assertDontSee('رواية شفوية غير معتمدة')
        ->assertDontSee('مسودة هجرة')->assertDontSee('محطة للأعضاء');
    // The source behind both accounts is listed once in the sources section.
    expect(substr_count(explode('id="section-sources"', $page->getContent())[1], 'كتاب أنساب العشيرة'))->toBe(1);
    $this->actingAs($this->reader)->get(route('community.entities.show', ['zain', 'f']))->assertSee('محطة للأعضاء');
});

test('locations are shared between entities without copies and follow the scope', function () {
    $shared = portalContent(CommunityLocation::class, $this->fakhd, ['name' => 'سهل حوران', 'country' => 'الأردن', 'governorate' => 'إربد', 'era' => 'historical', 'kind' => 'origin'], ['subjects' => [$this->fakhd->id, $this->sibling->id]]);
    $own = portalContent(CommunityLocation::class, $this->sub, ['name' => 'قرية الفرع', 'era' => 'current', 'kind' => 'gathering']);

    expect(CommunityLocation::query()->count())->toBe(2);
    $clan = $this->get(route('community.entities.show', ['zain', 'clan']))->assertOk()->getContent();
    expect(array_count_values(portalRecords($clan))["locations-{$shared->id}"])->toBe(1)
        ->and(portalRecords($clan))->toContain("locations-{$own->id}");
    $this->get(route('community.entities.show', ['zain', 'g']))->assertSee('سهل حوران')->assertSee('منطقة أصل')->assertSee('تاريخي')->assertDontSee('قرية الفرع');
    $this->get(route('community.entities.show', ['zain', 'clan', 'scope' => 'direct']))->assertDontSee('سهل حوران')->assertDontSee('قرية الفرع');
});

test('one scope drives every section: inherited audience, roll-up and sibling isolation', function () {
    $clanWide = portalContent(Diwan::class, $this->clan, ['name' => 'ديوان العشيرة', 'kind' => 'diwan', 'city' => 'إربد'], ['subjects' => [$this->clan->id], 'audiences' => [$this->clan->id]]);
    $subOwn = portalContent(Diwan::class, $this->sub, ['name' => 'مضافة الفرع', 'kind' => 'madafa', 'city' => 'إربد']);
    $siblingOwn = portalContent(EntityNarrative::class, $this->sibling, ['section' => 'overview', 'account' => 'primary', 'title' => 'تاريخ الفخذ المجاور', 'body' => '...']);

    $records = fn (string $slug, ?string $scope = null) => portalRecords($this->get(route('community.entities.show', ['zain', $slug, 'scope' => $scope]))->getContent());
    expect($records('s'))->toContain("diwans-{$clanWide->id}", "diwans-{$subOwn->id}")->not->toContain("history-{$siblingOwn->id}")
        ->and($records('s', 'direct'))->toBe(["diwans-{$subOwn->id}"])
        ->and($records('f'))->toContain("diwans-{$clanWide->id}", "diwans-{$subOwn->id}")->not->toContain("history-{$siblingOwn->id}")
        ->and($records('f', 'direct'))->toBe([])
        ->and($records('g'))->toContain("diwans-{$clanWide->id}", "history-{$siblingOwn->id}")->not->toContain("diwans-{$subOwn->id}")
        ->and($records('clan', 'direct'))->toBe(["diwans-{$clanWide->id}"]);
    $this->get(route('community.entities.show', ['zain', 's', 'scope' => 'direct']))->assertSee('data-entity-scope="direct"', false)->assertSee('تعرض الأقسام محتوى هذا المستوى فقط');
});

test('the genealogy preview reuses the published tree and invents no founder', function () {
    $before = [Person::query()->withoutGlobalScopes()->count(), PersonRelationship::query()->count(), Branch::query()->count()];

    $this->get(route('community.entities.show', ['zain', 'f']))->assertOk()
        ->assertSee('المؤسس')->assertSee(route('genealogy.person', ['zain', $this->mohammad->id, 'tree' => 'main']), false)
        ->assertSee('data-ancestor-chain', false)->assertSee('قاسم')
        ->assertSee('عمر')->assertSee('حمدان')->assertDontSee('سالم')->assertDontSee('مجهول')
        ->assertSee(route('genealogy.branch', ['zain', 'mohammad', 'tree' => 'main']), false)
        ->assertSee(route('genealogy.branch', ['zain', 'omar', 'tree' => 'main']), false)
        ->assertDontSee(route('genealogy.branch', ['zain', 'hamdan', 'tree' => 'main']), false)
        // With sub-branches: founders of published sub-entities only.
        ->assertSee('data-sub-founders', false)->assertSee(route('community.entities.show', ['zain', 's']), false);
    $this->get(route('community.entities.show', ['zain', 'f', 'scope' => 'direct']))->assertDontSee('data-sub-founders', false);
    $this->get(route('community.entities.show', ['zain', 'clan']))->assertSee('جذر الشجرة المرتبطة')->assertSee('قاسم الزين');
    $this->get(route('community.entities.show', ['zain', 'g']))->assertSee('لا يُنشأ مؤسس افتراضي');
    // Managers previewing see the unpublished branch's founder and the living child.
    $this->actingAs($this->admin)->get(route('community.entities.show', ['zain', 't', 'preview' => 1]))->assertSee('حمدان الزين');
    $this->get(route('community.entities.show', ['zain', 'f', 'preview' => 1]))->assertSee('سالم');
    expect([Person::query()->withoutGlobalScopes()->count(), PersonRelationship::query()->count(), Branch::query()->count()])->toBe($before);
});

test('statistics count distinct people per measure and never present records as a population', function () {
    $stat = fn (string $html, string $key) => preg_match('/data-stat="'.$key.'"><dt>[^<]*<\/dt><dd>([^<]*)<\/dd>/u', $html, $m) ? trim($m[1]) : null;

    $f = $this->get(route('community.entities.show', ['zain', 'f', 'scope' => 'direct']))->assertOk()
        ->assertSee('الإحصاءات الواردة مبنية على المعلومات المسجلة والمتاحة في منصة جذور، وقد لا تشمل جميع أفراد الفخذ أو العشيرة، ولا تمثل تعدادًا رسميًا للسكان.')
        ->assertSee('غير معروفة')->getContent();
    expect([$stat($f, 'registered'), $stat($f, 'verified'), $stat($f, 'published'), $stat($f, 'branches'), $stat($f, 'father-coverage'), $stat($f, 'living')])
        ->toBe(['5', '4', '3', '2', '75٪', null]);

    // The clan's tree, the fakhd and its sub-fakhd overlap; each person is counted once.
    $clan = $this->get(route('community.entities.show', ['zain', 'clan']))->getContent();
    expect([$stat($clan, 'registered'), $stat($clan, 'verified'), $stat($clan, 'published')])->toBe(['6', '5', '4']);
    $this->get(route('community.entities.show', ['zain', 'g', 'scope' => 'direct']))->assertSee('لا تتوفر أعداد مبنية على السجلات');

    // Members previewing see living people and branches not yet published.
    $preview = $this->actingAs($this->admin)->get(route('community.entities.show', ['zain', 'f', 'scope' => 'direct', 'preview' => 1]))->getContent();
    expect([$stat($preview, 'living'), $stat($preview, 'branches')])->toBe(['1', '3']);
});

test('a population estimate is shown only with an independent source', function () {
    $this->actingAs($this->admin);
    $input = ['name_ar' => 'كيان f', 'slug' => 'f', 'entity_type' => 'fakhd', 'parent_id' => $this->clan->id, 'branch_id' => $this->branchM->id, 'publication_status' => 'published', 'population_estimate' => 1200, 'population_estimate_year' => 2020];

    $this->put(route('management.entities.update', ['zain', $this->fakhd->id]), $input)->assertSessionHasErrors('population_estimate_source_id');
    $this->put(route('management.entities.update', ['zain', $this->fakhd->id]), [...$input, 'population_estimate_source_id' => $this->source->id])->assertSessionHasNoErrors();
    auth()->logout();

    $this->get(route('community.entities.show', ['zain', 'f']))->assertSee('1,200')->assertSee('تقدير 2020')->assertSee('كتاب أنساب العشيرة');
    $this->delete(route('management.sources.destroy', ['zain', $this->source->id]))->assertRedirect();
    expect(Source::query()->whereKey($this->source->id)->exists())->toBeTrue();
});

test('a shared diwan is one record on several fakhd pages with keyless Google Maps directions', function () {
    $diwan = portalContent(Diwan::class, $this->clan, ['name' => 'ديوان آل الزين', 'kind' => 'diwan', 'governorate' => 'إربد', 'city' => 'إربد', 'latitude' => 32.5556, 'longitude' => 35.85, 'google_place_id' => 'ChIJabc123XYZ_-', 'contact_name' => 'أبو محمد', 'contact_phone' => '+962 7 9000 0000', 'contact_consent' => false], ['subjects' => [$this->fakhd->id, $this->sibling->id]]);

    expect($diwan->directionsUrl())->toBe('https://www.google.com/maps/dir/?api=1&destination=32.5556%2C35.85&destination_place_id=ChIJabc123XYZ_-')
        ->and($diwan->embedUrl())->toBeNull();
    foreach (['f', 'g'] as $slug) {
        $this->get(route('community.entities.show', ['zain', $slug]))->assertSee('ديوان آل الزين')->assertSee('فتح الاتجاهات في Google Maps')
            ->assertSee(e($diwan->directionsUrl()), false)->assertDontSee('+962 7 9000 0000')->assertDontSee('<iframe', false);
    }
    expect(array_count_values(portalRecords($this->get(route('community.entities.show', ['zain', 'clan']))->getContent()))["diwans-{$diwan->id}"])->toBe(1)
        ->and(Diwan::query()->count())->toBe(1);

    $diwan->update(['contact_consent' => true]);
    config(['services.google_maps.embed_key' => 'test-key']);
    $this->get(route('community.entities.show', ['zain', 'f']))->assertSee('+962 7 9000 0000')->assertSee('maps/embed/v1/place?key=test-key&amp;q=place_id%3AChIJabc123XYZ_-', false);

    expect(Diwan::isGoogleMapsUrl('https://maps.app.goo.gl/AbCdEf'))->toBeTrue()
        ->and(Diwan::isGoogleMapsUrl('https://www.google.com/maps/place/x'))->toBeTrue()
        ->and(Diwan::isGoogleMapsUrl('http://www.google.com/maps/place/x'))->toBeFalse()
        ->and(Diwan::isGoogleMapsUrl('https://www.google.com.evil.example/maps'))->toBeFalse()
        ->and(Diwan::isGoogleMapsUrl('https://www.google.com/search?q=x'))->toBeFalse()
        ->and(Diwan::isGoogleMapsUrl('javascript:alert(1)'))->toBeFalse();
});

test('diwan input is validated and a private residence needs recorded consent to be published', function () {
    $this->actingAs($this->admin);
    $url = route('management.entities.content.store', ['zain', $this->fakhd->id, 'diwans']);
    $this->post($url, portalForm(['name' => 'د', 'kind' => 'diwan', 'maps_url' => 'https://evil.example/maps']))->assertSessionHasErrors('maps_url');
    $this->post($url, portalForm(['name' => 'د', 'kind' => 'diwan', 'latitude' => 32.5]))->assertSessionHasErrors('longitude');
    $this->post($url, portalForm(['name' => 'د', 'kind' => 'diwan', 'latitude' => 120, 'longitude' => 35]))->assertSessionHasErrors('latitude');
    $this->post($url, portalForm(['name' => 'منزل أبي سالم', 'kind' => 'madafa', 'is_private_residence' => 1], ['publication_status' => 'published']))->assertSessionHasErrors('residence_consent');
    $this->post($url, portalForm(['name' => 'منزل أبي سالم', 'kind' => 'madafa', 'is_private_residence' => 1]))->assertSessionHasNoErrors();
    $home = Diwan::query()->where('name', 'منزل أبي سالم')->sole();
    $review = route('management.entities.content.review', ['zain', $this->fakhd->id, 'diwans', $home->id]);

    $this->post($review, ['decision' => 'publish'])->assertSessionHasErrors('residence_consent');
    $home->update(['residence_consent' => true]);
    $this->post($review, ['decision' => 'publish'])->assertSessionHasNoErrors();
    expect($home->fresh()->publication_status)->toBe('published');
});

test('notable people reuse Person records and never expose hidden people', function () {
    $public = portalContent(EntityAchievement::class, $this->fakhd, ['person_id' => $this->omar->id, 'kind' => 'position', 'category' => 'judiciary', 'title' => 'قاضي المحكمة الشرعية', 'organization' => 'محكمة إربد']);
    portalContent(EntityAchievement::class, $this->fakhd, ['person_id' => $this->hamdan->id, 'kind' => 'achievement', 'category' => 'academic', 'title' => 'دكتوراه في التاريخ']);
    $hidden = portalContent(EntityAchievement::class, $this->fakhd, ['person_id' => $this->living->id, 'kind' => 'achievement', 'category' => 'sports', 'title' => 'بطولة رياضية للحي']);
    $shared = portalContent(EntityAchievement::class, $this->fakhd, ['person_id' => $this->omar->id, 'kind' => 'achievement', 'category' => 'scholar', 'title' => 'مؤلف كتاب'], ['subjects' => [$this->fakhd->id, $this->sibling->id]]);

    $page = $this->get(route('community.entities.show', ['zain', 'f']))->assertOk()
        ->assertSee('تعرض هذه الصفحة الشخصيات والمناصب والإنجازات وفق المعلومات المتاحة والمدخلة والموثقة في المنصة. ولا تعني القائمة حصر جميع شخصيات الفخذ أو ترتيبًا لأهميتهم أو مكانتهم، ويمكن تقديم معلومات أو وثائق لإضافة شخصيات أخرى أو تحديث البيانات.')
        ->assertSee(route('genealogy.person', ['zain', $this->omar->id, 'tree' => 'main']), false)
        ->assertDontSee('بطولة رياضية للحي')->assertDontSee('سالم الزين')
        // Alphabetical by name: حمدان before عمر, whatever the category.
        ->assertSeeInOrder(['حمدان الزين', 'عمر الزين']);
    expect(portalRecords($page->getContent()))->not->toContain("achievements-{$hidden->id}");
    $this->get(route('community.entities.show', ['zain', 'f', 'category' => 'judiciary']))->assertSee('قاضي المحكمة الشرعية')->assertDontSee('دكتوراه في التاريخ');
    $this->get(route('community.entities.show', ['zain', 'g', 'scope' => 'direct']))->assertSee('مؤلف كتاب')->assertDontSee('قاضي المحكمة الشرعية');

    // Members of the family see the living person's record.
    $this->actingAs($this->reader)->get(route('community.entities.show', ['zain', 'f']))->assertSee('بطولة رياضية للحي');
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(route('community.entities.show', ['zain', 'f']))->assertDontSee('بطولة رياضية للحي');
    // Public JSON endpoints still withhold the living person.
    auth()->logout();
    $this->getJson(route('genealogy.search', ['zain', 'q' => 'سالم']))->assertOk()->assertJsonCount(0, 'data');

    // The person cannot be deleted or merged away while achievements point at them.
    $this->actingAs($this->admin)->delete(route('management.people.destroy', ['zain', $this->living->id]))->assertSessionHasErrors('person');
    expect(app(PersonMergeService::class)->plan($this->tree, $this->hamdan->id, $this->omar->id)['blockers'])->toContain('السجل المدموج مرتبط بسجلات «شخصيات وإنجازات»؛ انقلها إلى السجل الباقي من صفحات الكيانات أولًا.')
        ->and($public->person_id)->toBe($this->omar->id)->and($shared->entityLinks()->count())->toBe(2);
});

test('editors draft and submit; publishers review, decline with a reason and publish', function () {
    $this->actingAs($this->editor);
    $store = route('management.entities.content.store', ['zain', $this->fakhd->id, 'history']);
    $fields = ['section' => 'founding', 'account' => 'primary', 'title' => 'رواية التأسيس', 'body' => 'نص الرواية'];
    $this->post($store, portalForm($fields, ['publication_status' => 'published']))->assertSessionHasErrors('publication_status');
    $this->post($store, portalForm($fields, ['verification_status' => 'verified']))->assertSessionHasErrors('verification_status');
    $this->post($store, portalForm($fields))->assertSessionHasNoErrors();
    $record = EntityNarrative::query()->sole();
    $edit = fn (string $action) => route('management.entities.content.'.$action, ['zain', $this->fakhd->id, 'history', $record->id]);

    $this->post($edit('submit'))->assertSessionHasNoErrors();
    expect($record->fresh()->publication_status)->toBe('submitted');
    $this->post($edit('review'), ['decision' => 'publish'])->assertForbidden();

    $this->actingAs($this->admin)->get(route('management.entities.review', 'zain'))->assertOk()->assertSee('رواية التأسيس');
    $this->post($edit('review'), ['decision' => 'decline'])->assertSessionHasErrors('review_note');
    $this->post($edit('review'), ['decision' => 'decline', 'review_note' => 'أضف مصدرًا'])->assertSessionHasNoErrors();
    expect($record->fresh()->publication_status)->toBe('draft');
    $this->actingAs($this->editor)->get($edit('edit'))->assertSee('أضف مصدرًا');
    $this->post($edit('citations.store'), ['source_id' => $this->source->id, 'verification_status' => 'verified'])->assertSessionHasErrors('verification_status');
    $this->post($edit('citations.store'), ['source_id' => $this->source->id, 'locator' => 'ص ٣', 'verification_status' => 'pending_review'])->assertSessionHasNoErrors();
    $this->post($edit('submit'));

    $this->actingAs($this->admin)->post($edit('review'), ['decision' => 'publish'])->assertSessionHasNoErrors();
    $this->put(route('management.entities.content.citations.update', ['zain', $this->fakhd->id, 'history', $record->id, $record->citations()->sole()->id]), ['verification_status' => 'sourced'])->assertSessionHasNoErrors();
    $this->actingAs($this->editor)->put($edit('update'), portalForm([...$fields, 'title' => 'تعديل بعد النشر']))->assertSessionHasErrors('publication_status');
    expect($record->fresh()->title)->toBe('رواية التأسيس');
    auth()->logout();
    $this->get(route('community.entities.show', ['zain', 'f']))->assertSee('رواية التأسيس')->assertSee('كتاب أنساب العشيرة');

    expect(AuditLog::query()->where('auditable_type', EntityNarrative::class)->where('auditable_id', $record->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['created', 'submitted', 'review_decline', 'submitted', 'review_publish'])
        ->and(AuditLog::query()->where('auditable_type', EntityNarrative::class)->where('action', 'created')->sole()->after['subject_ids'])->toBe([$this->fakhd->id]);
});

test('entity managers write only within their scope, never for siblings or ancestors', function () {
    CommunityEntityManager::query()->create(['community_entity_id' => $this->fakhd->id, 'user_id' => $this->reader->id, 'role' => 'editor']);
    $this->actingAs($this->reader);
    $fields = ['section' => 'overview', 'account' => 'primary', 'title' => 'نبذة', 'body' => 'نص'];

    $this->post(route('management.entities.content.store', ['zain', $this->sub->id, 'history']), portalForm($fields))->assertSessionHasNoErrors();
    $this->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'history']), portalForm($fields, ['subjects' => [$this->fakhd->id, $this->sibling->id]]))->assertSessionHasErrors('subjects');
    $this->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'history']), portalForm($fields, ['audiences' => [$this->clan->id]]))->assertSessionHasErrors('subjects');
    $this->get(route('management.entities.content.index', ['zain', $this->sibling->id, 'history']))->assertForbidden();
    $this->post(route('management.entities.content.store', ['zain', $this->clan->id, 'history']), portalForm($fields))->assertForbidden();
    expect(EntityNarrative::query()->count())->toBe(1);

    // A clan diwan addressed to the fakhd shows on the manager's page but stays the clan's to edit.
    $clanDiwan = portalContent(Diwan::class, $this->clan, ['name' => 'ديوان مركزي', 'kind' => 'diwan'], ['audiences' => [$this->clan->id]]);
    expect($clanDiwan->isEditableBy($this->reader))->toBeFalse();
    $this->get(route('management.entities.content.edit', ['zain', $this->fakhd->id, 'diwans', $clanDiwan->id]))->assertNotFound();
    $this->get(route('management.entities.content.edit', ['zain', $this->clan->id, 'diwans', $clanDiwan->id]))->assertForbidden();
});

test('families cannot touch each other\'s content or cite each other\'s sources', function () {
    $other = Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other']);
    $otherAdmin = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $other->id, 'user_id' => $otherAdmin->id, 'role' => 'admin']);
    $otherSource = $other->sources()->create(['source_type' => 'book', 'title' => 'مصدر غريب', 'verification_status' => 'sourced']);
    $diwan = portalContent(Diwan::class, $this->fakhd, ['name' => 'ديوان', 'kind' => 'diwan']);

    $this->actingAs($otherAdmin)->get(route('management.entities.content.index', ['zain', $this->fakhd->id, 'diwans']))->assertForbidden();
    $this->get(route('management.entities.content.index', ['other', $this->fakhd->id, 'diwans']))->assertNotFound();
    $this->get(route('management.entities.review', 'zain'))->assertForbidden();

    $this->actingAs($this->admin)->post(route('management.entities.content.citations.store', ['zain', $this->fakhd->id, 'diwans', $diwan->id]), ['source_id' => $otherSource->id, 'verification_status' => 'pending_review'])->assertSessionHasErrors('source_id');
    // Sources evidencing cross-family links stay reviewer-only and cannot back community content.
    $linkSource = $this->family->sources()->create(['source_type' => 'document', 'title' => 'مستند مطابقة', 'verification_status' => 'pending_review']);
    $otherPerson = $other->familyTrees()->create(['name_ar' => 'ش', 'slug' => 'm'])->people()->create(['first_name_ar' => 'ج', 'full_name_ar' => 'جد آخر', 'status' => 'historical', 'is_living' => false]);
    $link = CrossFamilyLink::query()->create(['left_person_id' => $this->omar->id, 'right_person_id' => $otherPerson->id, 'left_family_id' => $this->family->id, 'right_family_id' => $other->id, 'kind' => 'same_person', 'claim' => 'x', 'submitted_by' => $this->admin->id]);
    Citation::query()->create(['source_id' => $linkSource->id, 'family_tree_id' => $this->tree->id, 'cross_family_link_id' => $link->id, 'field_key' => 'cross_family_match', 'verification_status' => 'pending_review']);
    $this->post(route('management.entities.content.citations.store', ['zain', $this->fakhd->id, 'diwans', $diwan->id]), ['source_id' => $linkSource->id, 'verification_status' => 'pending_review'])->assertSessionHasErrors('source_id');
    $foreignEntity = CommunityEntity::query()->create(['family_id' => $other->id, 'entity_type' => 'clan', 'name_ar' => 'x', 'slug' => 'x', 'publication_status' => 'published']);
    $this->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'diwans']), portalForm(['name' => 'د', 'kind' => 'diwan'], ['subjects' => [$foreignEntity->id]]))->assertSessionHasErrors('subjects.0');
    $this->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'achievements']), portalForm(['person_id' => $other->familyTrees()->create(['name_ar' => 't', 'slug' => 't'])->people()->create(['first_name_ar' => 'غريب', 'full_name_ar' => 'غريب'])->id, 'kind' => 'position', 'category' => 'other', 'title' => 't']))->assertSessionHasErrors('person_id');
    expect(fn () => app(CommunityContentService::class)->save($this->admin, $this->fakhd, new Diwan, ['name' => 'x', 'kind' => 'diwan'], ['publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [$foreignEntity->id], 'audiences' => []]))
        ->toThrow(ValidationException::class);
});

test('hidden entities contribute no content, statistics or founders to public pages', function () {
    $this->sub->update(['publication_status' => 'draft']);
    $subDiwan = portalContent(Diwan::class, $this->sub, ['name' => 'ديوان فرع غير منشور', 'kind' => 'diwan']);

    $page = $this->get(route('community.entities.show', ['zain', 'f']))->assertOk()->assertDontSee('ديوان فرع غير منشور')->assertDontSee('data-sub-founders', false)->getContent();
    expect(portalRecords($page))->not->toContain("diwans-{$subDiwan->id}");
    $this->get(route('community.entities.show', ['zain', 's']))->assertNotFound();
});

test('administration pages for every module render for managers', function () {
    $diwan = portalContent(Diwan::class, $this->fakhd, ['name' => 'ديوان قيد المراجعة', 'kind' => 'diwan'], ['publication_status' => 'submitted']);
    $this->actingAs($this->editor);

    $this->get(route('management.entities.edit', ['zain', $this->fakhd->id]))->assertOk()->assertSee('محتوى الصفحة')
        ->assertSee(route('management.entities.content.index', ['zain', $this->fakhd->id, 'achievements']), false);
    foreach (array_keys(CommunityContentModules::MODULES) as $module) {
        $this->get(route('management.entities.content.index', ['zain', $this->fakhd->id, $module]))->assertOk();
        $this->get(route('management.entities.content.create', ['zain', $this->fakhd->id, $module]))->assertOk()->assertSee('data-content-form', false);
    }
    $this->get(route('management.entities.content.edit', ['zain', $this->fakhd->id, 'diwans', $diwan->id]))->assertOk()->assertSee('ديوان قيد المراجعة')->assertDontSee('data-review-form', false);
    $this->get(route('management.entities.content.index', ['zain', $this->sub->id, 'diwans']))->assertOk();
    $this->actingAs($this->admin)->get(route('management.families.dashboard', 'zain'))->assertOk()->assertSee('data-entity-content-pending', false)
        ->assertSee(route('management.entities.review', 'zain'), false);
    $this->get(route('management.entities.content.edit', ['zain', $this->fakhd->id, 'diwans', $diwan->id]))->assertSee('data-review-form', false);
    $this->actingAs($this->reader)->get(route('management.entities.content.index', ['zain', $this->fakhd->id, 'diwans']))->assertForbidden();
});
