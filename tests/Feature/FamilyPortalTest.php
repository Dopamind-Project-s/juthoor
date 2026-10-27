<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\Diwan;
use App\Models\EntityNarrative;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyPortalSetting;
use App\Models\MediaItem;
use App\Models\NewsPost;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Community\CommunityContentService;
use App\Services\Community\MemberDirectory;
use App\Services\Portal\PortalSettings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->family = Family::query()->create(['name_ar' => 'عشيرة الميم', 'slug' => 'meem', 'is_active' => true, 'short_description' => 'نبذة من سجل العائلة', 'location' => 'إربد']);
    $this->tree = $this->family->familyTrees()->create(['name_ar' => 'شجرة الميم', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $person = fn (string $name, array $extra = []) => $this->tree->people()->create(['first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false, ...$extra]);
    $edge = fn (Person $child, Person $father, string $status = 'verified') => PersonRelationship::query()->create(['family_tree_id' => $this->tree->id, 'person_id' => $child->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => $status]);
    $this->root = $person('الجد مالك');
    $this->founder = $person('مؤسس الفخذ سالم', ['verification_status' => 'sourced']);
    $this->son = $person('ابن سالم');
    $this->living = $person('حفيد حي', ['is_living' => true]);
    $this->pending = $person('مؤسس غير منشور', ['verification_status' => 'pending_review']);
    $edge($this->founder, $this->root);
    $edge($this->son, $this->founder);
    $edge($this->living, $this->son);
    $edge($this->pending, $this->root, 'pending_review');
    $this->tree->update(['root_person_id' => $this->root->id]);
    $this->branch = Branch::query()->create(['family_tree_id' => $this->tree->id, 'name_ar' => 'فرع سالم', 'slug' => 'salem', 'root_person_id' => $this->founder->id, 'is_active' => true, 'verification_status' => 'verified']);
    $this->hiddenBranch = Branch::query()->create(['family_tree_id' => $this->tree->id, 'name_ar' => 'فرع مخفي', 'slug' => 'hidden', 'root_person_id' => $this->pending->id, 'is_active' => false, 'verification_status' => 'pending_review']);
    $entity = fn (string $slug, string $type, ?CommunityEntity $parent, string $status = 'published', array $extra = []) => CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $parent?->id, 'entity_type' => $type, 'name_ar' => 'كيان '.$slug, 'slug' => $slug, 'publication_status' => $status, 'family_tree_id' => $this->tree->id, ...$extra]);
    $this->clan = $entity('clan', 'clan', null);
    $this->fakhd = $entity('salem', 'fakhd', $this->clan, 'published', ['branch_id' => $this->branch->id]);
    $this->draftFakhd = $entity('draft', 'fakhd', $this->clan, 'draft', ['branch_id' => $this->hiddenBranch->id]);
    $this->subOfDraft = $entity('under-draft', 'sub_fakhd', $this->draftFakhd);
    $this->member = function (string $role, ?Family $family = null): User {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        FamilyMembership::query()->create(['family_id' => ($family ?? $this->family)->id, 'user_id' => $user->id, 'role' => $role]);

        return $user->fresh();
    };
    $this->admin = ($this->member)('admin');
    $this->content = function (CommunityEntity $owner, $record, array $fields, array $governance = []) {
        return app(CommunityContentService::class)->save($this->admin, $owner, $record, $fields, ['publication_status' => 'published', 'verification_status' => 'verified', 'audience' => 'public', 'subjects' => [], 'audiences' => [], ...$governance]);
    };
    $this->media = function (CommunityEntity $owner, array $extra = []): MediaItem {
        return MediaItem::query()->forceCreate(['family_id' => $this->family->id, 'owner_entity_id' => $owner->id, 'uuid' => (string) Str::uuid(), 'disk' => 'media', 'path' => 'x/'.Str::random(8).'.jpg', 'original_name' => 'a.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'checksum' => hash('sha256', Str::random()), 'category' => 'image', 'title' => 'صورة ديوان قديم', 'alt_text' => 'ديوان حجري', 'rights_status' => 'owned', 'publication_status' => 'published', 'verification_status' => 'verified', 'audience' => 'public', ...$extra]);
    };
});

test('the portal is database-backed: statistics, hierarchy and genealogy preview never expose hidden branches, unpublished entities or private people', function () {
    $html = $this->get('/families/meem')->assertOk()->getContent();
    $stat = fn (string $key) => preg_match('/data-stat="'.$key.'"><dt>.*?<\/dt><dd>([\d,]+)<\/dd>/su', $html, $match) ? (int) str_replace(',', '', $match[1]) : null;
    expect($stat('registered'))->toBe(Person::query()->where('family_tree_id', $this->tree->id)->count())
        ->and($stat('published'))->toBe(Person::query()->where('family_tree_id', $this->tree->id)->publiclyVisible()->count())
        ->and($stat('published'))->toBe(3)
        ->and($stat('verified'))->toBe(2)
        ->and($stat('branches'))->toBe(1)
        ->and($stat('fakhds'))->toBe(1);
    expect($html)->toContain('كيان clan')->toContain('كيان salem')->toContain('المؤسس: مؤسس الفخذ سالم')->toContain('ليست تعدادًا لأفراد العائلة')
        ->not->toContain('كيان draft')->not->toContain('كيان under-draft')->not->toContain('فرع مخفي')
        ->not->toContain('حفيد حي')->not->toContain('مؤسس غير منشور');
    // Each entity appears once in the hierarchy.
    expect(substr_count($html, 'data-node="entity-'.$this->fakhd->id.'"'))->toBe(1);
    // Without entities the published branches are shown instead.
    CommunityEntity::query()->where('family_id', $this->family->id)->update(['publication_status' => 'draft']);
    $this->get('/families/meem')->assertOk()->assertSee('data-node="branch-'.$this->branch->id.'"', false)->assertDontSee('فرع مخفي');
});

test('content sections come through the entity scope: scheduled, members-only and unpublished items stay hidden and shared items appear once', function () {
    ($this->content)($this->clan, new EntityNarrative, ['section' => 'origins', 'account' => 'primary', 'title' => 'أصول العشيرة', 'body' => 'نص منشور مع مصدر']);
    ($this->content)($this->clan, new EntityNarrative, ['section' => 'origins', 'account' => 'primary', 'title' => 'مسودة تاريخ', 'body' => 'x'], ['publication_status' => 'draft']);
    ($this->content)($this->fakhd, new Diwan, ['name' => 'ديوان سالم', 'kind' => 'diwan', 'city' => 'إربد']);
    ($this->content)($this->draftFakhd, new Diwan, ['name' => 'ديوان الفخذ المخفي', 'kind' => 'diwan']);
    $shared = ($this->content)($this->fakhd, new NewsPost, ['category' => 'news', 'title' => 'خبر مشترك', 'body' => 'نص'], ['subjects' => [$this->fakhd->id, $this->clan->id]]);
    ($this->content)($this->clan, new NewsPost, ['category' => 'news', 'title' => 'خبر مجدول', 'body' => 'نص', 'publish_at' => now()->addWeek()]);
    ($this->content)($this->clan, new NewsPost, ['category' => 'news', 'title' => 'خبر للأعضاء', 'body' => 'نص'], ['audience' => 'members']);

    $html = $this->actingAs($this->admin)->get('/families/meem')->assertOk()->getContent();
    expect($html)->toContain('أصول العشيرة')->toContain('ديوان سالم')->toContain('خبر مشترك')
        ->not->toContain('مسودة تاريخ')->not->toContain('ديوان الفخذ المخفي')->not->toContain('خبر مجدول')
        // The landing page applies public rules even to signed-in members.
        ->not->toContain('خبر للأعضاء')
        ->and(substr_count($html, 'data-record="news-'.$shared->id.'"'))->toBe(1);
});

test('directory previews show listed public profiles with public preview fields only', function () {
    $directory = app(MemberDirectory::class);
    $public = ($this->member)('reader');
    $directory->save($public, $this->family, ['display_name' => 'م. عمر العام', 'profession' => 'مهندس', 'city' => 'إربد', 'organization' => 'شركة سرية', 'contact_phone' => '+962700000001', 'contact_email' => 'secret@example.com', 'audience' => 'public'],
        ['profession' => 'public', 'city' => 'public', 'organization' => 'public', 'contact_phone' => 'public', 'contact_email' => 'public'], true, null, false);
    $membersOnly = ($this->member)('reader');
    $directory->save($membersOnly, $this->family, ['display_name' => 'عضو للأعضاء فقط', 'audience' => 'members'], [], true, null, false);
    $unlisted = ($this->member)('reader');
    $directory->save($unlisted, $this->family, ['display_name' => 'غير مدرج', 'audience' => 'public'], [], false, null, false);

    $html = $this->get('/families/meem')->assertOk()->getContent();
    expect($html)->toContain('م. عمر العام')->toContain('مهندس')
        ->not->toContain('+962700000001')->not->toContain('secret@example.com')->not->toContain('شركة سرية')
        ->not->toContain('عضو للأعضاء فقط')->not->toContain('غير مدرج');
});

test('branding: administrators edit a draft, preview it privately and publish it; visitors see only the published copy', function () {
    $editor = ($this->member)('editor');
    $this->actingAs($editor)->get(route('management.portal.edit', 'meem'))->assertForbidden();
    $this->actingAs($editor)->get(route('management.portal.preview', 'meem'))->assertForbidden();
    $this->actingAs($editor)->put(route('management.portal.update', 'meem'), ['display_name' => 'x'])->assertForbidden();
    $form = fn (array $changes = []) => [
        'display_name' => 'آل الميم', 'intro' => 'مقدمة جديدة', 'description' => "سطر أول\n<script>alert(1)</script>", 'hero_title' => 'جذور آل الميم', 'hero_subtitle' => 'من إربد',
        'accent' => 'maroon', 'cta_label' => 'تصفح الشجرة', 'cta_target' => 'tree', 'public_email' => 'family@example.org', 'footer_text' => 'تذييل العائلة',
        'contact_links' => [['label' => 'الموقع', 'url' => 'https://example.org']],
        'sections' => ['news' => ['visible' => 1, 'order' => 1], 'statistics' => ['visible' => 1, 'order' => 2], 'directory' => ['visible' => 0, 'order' => 3]],
        ...$changes,
    ];
    $this->actingAs($this->admin)->get(route('management.portal.edit', 'meem'))->assertOk()->assertSee('موقع العائلة');
    $this->actingAs($this->admin)->put(route('management.portal.update', 'meem'), $form())->assertSessionHasNoErrors();
    // Draft only: the public page is unchanged.
    $this->get('/families/meem')->assertOk()->assertSee('عشيرة الميم')->assertDontSee('جذور آل الميم');
    $preview = $this->actingAs($this->admin)->get(route('management.portal.preview', 'meem'))->assertOk()->assertSee('جذور آل الميم')->assertSee('معاينة المسودة');
    expect($preview->headers->get('X-Robots-Tag'))->toContain('noindex');
    $this->actingAs($this->admin)->post(route('management.portal.publish', 'meem'))->assertSessionHasNoErrors();
    $html = $this->get('/families/meem')->assertOk()->getContent();
    expect($html)->toContain('جذور آل الميم')->toContain('--j-brand:#5e2430')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->not->toContain('<script>alert(1)')
        ->toContain('https://example.org')->toContain('family@example.org')
        ->not->toContain('id="portal-directory"')
        ->and(strpos($html, 'id="portal-news"'))->toBeLessThan(strpos($html, 'id="portal-statistics"'));
    expect(AuditLog::query()->where('family_id', $this->family->id)->pluck('action')->all())->toContain('portal.draft_saved', 'portal.published');
    // Unsafe values are refused.
    foreach ([['accent' => 'custom'], ['contact_links' => [['label' => 'x', 'url' => 'javascript:alert(1)']]], ['contact_links' => [['label' => 'x', 'url' => 'http://insecure.example']]], ['root_entity_id' => 999999]] as $bad) {
        $this->actingAs($this->admin)->put(route('management.portal.update', 'meem'), $form($bad))->assertSessionHasErrors();
    }
    expect(FamilyPortalSetting::query()->sole()->published['accent'])->toBe('maroon');
});

test('cover and logo must be public images of the family; restricted or other families\' media are refused', function () {
    $public = ($this->media)($this->fakhd);
    $restricted = ($this->media)($this->fakhd, ['audience' => 'restricted']);
    $draft = ($this->media)($this->fakhd, ['publication_status' => 'draft']);
    $onHiddenEntity = ($this->media)($this->draftFakhd);
    $other = Family::query()->create(['name_ar' => 'عائلة أخرى', 'slug' => 'other', 'is_active' => true]);
    $otherEntity = CommunityEntity::query()->create(['family_id' => $other->id, 'entity_type' => 'clan', 'name_ar' => 'أخرى', 'slug' => 'o', 'publication_status' => 'published']);
    $foreign = MediaItem::query()->forceCreate([...$public->only(['disk', 'original_name', 'extension', 'mime_type', 'size', 'category', 'title', 'rights_status', 'publication_status', 'verification_status', 'audience']), 'family_id' => $other->id, 'owner_entity_id' => $otherEntity->id, 'uuid' => (string) Str::uuid(), 'path' => 'y.jpg', 'checksum' => hash('sha256', 'foreign')]);
    $settings = app(PortalSettings::class);
    $base = $settings->defaults($this->family);
    foreach ([$restricted, $draft, $onHiddenEntity, $foreign] as $media) {
        expect(fn () => $settings->saveDraft($this->admin, $this->family, [...$base, 'cover_media' => $media->uuid]))->toThrow(ValidationException::class);
    }
    $settings->saveDraft($this->admin, $this->family, [...$base, 'cover_media' => $public->uuid, 'logo_media' => $public->uuid]);
    $settings->publish($this->admin, $this->family);
    $this->get('/families/meem')->assertOk()->assertSee(route('media.show', [$public->uuid, 'display']), false)->assertSee('portal-hero has-cover', false);
    // Unpublishing the image later removes it from the page without republishing settings.
    $public->update(['publication_status' => 'archived']);
    $this->get('/families/meem')->assertOk()->assertDontSee($public->uuid);
});

test('two families have independent portals from one platform', function () {
    $other = Family::query()->create(['name_ar' => 'عشيرة النون', 'slug' => 'noon', 'is_active' => true]);
    $other->familyTrees()->create(['name_ar' => 'شجرة النون', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $otherAdmin = ($this->member)('admin', $other);
    app(PortalSettings::class)->saveDraft($otherAdmin, $other, [...app(PortalSettings::class)->defaults($other), 'hero_title' => 'بوابة النون', 'accent' => 'night']);
    app(PortalSettings::class)->publish($otherAdmin, $other);
    // One family's administrator cannot change another family's site.
    $this->actingAs($otherAdmin)->put(route('management.portal.update', 'meem'), ['display_name' => 'x'])->assertForbidden();
    $this->get('/families/noon')->assertOk()->assertSee('بوابة النون')->assertSee('--j-brand:#1d2f45', false)->assertDontSee('كيان salem')->assertDontSee('عشيرة الميم');
    $this->get('/families/meem')->assertOk()->assertSee('كيان salem')->assertDontSee('بوابة النون');
    $other->update(['is_active' => false]);
    $this->get('/families/noon')->assertNotFound();
});

test('the landing page avoids N+1 queries as content grows', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/families/meem')->assertOk();

        return count(DB::getQueryLog());
    };
    $add = function (int $from, int $to): void {
        foreach (range($from, $to) as $i) {
            $entity = CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $this->clan->id, 'entity_type' => 'fakhd', 'name_ar' => 'فخذ '.$i, 'slug' => 'f'.$i, 'publication_status' => 'published']);
            ($this->content)($entity, new Diwan, ['name' => 'ديوان '.$i, 'kind' => 'diwan']);
            ($this->content)($entity, new NewsPost, ['category' => 'news', 'title' => 'خبر '.$i, 'body' => 'نص']);
            ($this->content)($entity, new EntityNarrative, ['section' => 'origins', 'account' => 'primary', 'title' => 'تاريخ '.$i, 'body' => 'نص']);
        }
    };
    // Past every display limit, more entities and records must not add queries.
    $add(1, 8);
    $eight = $count();
    $add(9, 24);
    expect($count())->toBe($eight);
});

test('legacy Al-Zoubi routes keep working; the dynamic portal is available alongside and canonical redirects wait for the flag', function () {
    $this->seed(DatabaseSeeder::class);
    $this->get('/al-zoubi')->assertOk();
    $this->get('/al-zoubi/branches')->assertOk();
    $this->get('/families/al-zoubi')->assertRedirect('/al-zoubi');
    $portal = $this->get('/families/al-zoubi/portal')->assertOk()->assertSee('عشيرة الزعبي')->assertSee('فروع منشورة')->getContent();
    $person = Family::query()->where('slug', 'al-zoubi')->sole()->primaryTree->people()->publiclyVisible()->firstOrFail();
    $this->get(route('genealogy.person', ['al-zoubi', $person->id, 'tree' => 'main']))->assertOk();
    $this->get(route('genealogy.tree', ['al-zoubi', 'q' => 'زعب']))->assertOk();
    // No legacy static estimate is presented as a statistic.
    expect($portal)->not->toContain('1505')->not->toContain('1,505');

    config(['portals.canonical_portal' => true]);
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->middleware('web')->group(base_path('routes/web.php'));
    $router->getRoutes()->refreshNameLookups();
    $this->get('/al-zoubi')->assertRedirect(route('portal.show', 'al-zoubi'));
    $this->get('/families/al-zoubi')->assertOk()->assertSee('عشيرة الزعبي');
    $this->get('/al-zoubi/history')->assertOk();
});
