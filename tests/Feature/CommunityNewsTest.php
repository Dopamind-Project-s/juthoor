<?php

use App\Models\AuditLog;
use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\CommunityEvent;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\MediaItem;
use App\Models\NewsPost;
use App\Models\Role;
use App\Models\User;
use App\Services\Community\CommunityContentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->family = Family::query()->create(['name_ar' => 'عائلة زين', 'slug' => 'zain']);
    $this->tree = $this->family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $this->deceased = $this->tree->people()->create(['first_name_ar' => 'سالم', 'full_name_ar' => 'سالم الزين', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => true]);
    $this->historic = $this->tree->people()->create(['first_name_ar' => 'قاسم', 'full_name_ar' => 'قاسم الزين', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $entity = fn (string $slug, string $type, ?CommunityEntity $parent, string $status = 'published') => CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $parent?->id, 'entity_type' => $type, 'name_ar' => 'كيان '.$slug, 'slug' => $slug, 'publication_status' => $status]);
    $this->clan = $entity('clan', 'clan', null);
    $this->fakhd = $entity('f', 'fakhd', $this->clan);
    $this->sub = $entity('s', 'sub_fakhd', $this->fakhd);
    $this->sibling = $entity('g', 'fakhd', $this->clan);
    foreach (['admin', 'editor', 'reader'] as $role) {
        $user = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $user->id, 'role' => $role]);
        $this->{$role} = $user->fresh();
    }
});

/** @param  array<string, mixed>  $governance */
function newsPost(CommunityEntity $owner, array $fields, array $governance = [], ?User $as = null): Model
{
    $as ??= User::query()->whereHas('familyMemberships', fn ($q) => $q->where('role', 'admin')->where('family_id', $owner->family_id))->orderBy('id')->firstOrFail();

    return app(CommunityContentService::class)->save($as, $owner, new NewsPost, ['category' => 'news', 'body' => 'نص الخبر', ...$fields],
        ['publication_status' => 'published', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [], 'audiences' => [], ...$governance]);
}

/** @return array<string, mixed> */
function newsForm(array $fields = [], array $governance = []): array
{
    return ['category' => 'news', 'title' => 'خبر', 'body' => 'نص', ...$fields, 'publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public', ...$governance];
}

/** @return array<int, string> */
function newsRecords(object $test, string $url): array
{
    preg_match_all('/data-record="(news-\d+)"/', $test->get($url)->assertOk()->getContent(), $matches);

    return $matches[1];
}

test('news follows the shared hierarchy: roll-up, targeted audience, sibling isolation, one record', function () {
    $sub = newsPost($this->sub, ['title' => 'خبر الفرع']);
    $clanWide = newsPost($this->clan, ['title' => 'إعلان للعشيرة كلها', 'category' => 'announcement'], ['audiences' => [$this->clan->id]]);
    $sibling = newsPost($this->sibling, ['title' => 'خبر الفخذ المجاور']);
    $shared = newsPost($this->fakhd, ['title' => 'خبر مشترك'], ['subjects' => [$this->fakhd->id, $this->sub->id]]);
    $page = fn (string $slug, ?string $scope = null) => newsRecords($this, route('community.entities.show', ['zain', $slug, 'scope' => $scope]));

    expect($page('f'))->toContain("news-{$sub->id}", "news-{$clanWide->id}", "news-{$shared->id}")->not->toContain("news-{$sibling->id}")
        ->and(array_count_values($page('clan'))["news-{$shared->id}"])->toBe(1)
        ->and($page('f', 'direct'))->toBe(["news-{$shared->id}"])
        ->and($page('g'))->toContain("news-{$clanWide->id}", "news-{$sibling->id}")->not->toContain("news-{$sub->id}")
        ->and(NewsPost::query()->count())->toBe(4);
    // The full list paginates the same scope and searches only what the viewer can see.
    expect(newsRecords($this, route('community.entities.news', ['zain', 'f', 'q' => 'مشترك'])))->toBe(["news-{$shared->id}"])
        ->and(newsRecords($this, route('community.entities.news', ['zain', 'f', 'category' => 'announcement'])))->toBe(["news-{$clanWide->id}"]);
    $this->get(route('community.news.show', ['zain', $sibling->id]))->assertOk()->assertSee('خبر الفخذ المجاور');
});

test('scheduled news stays hidden until its time and expiry follows the configured policy', function () {
    $this->travelTo(now()->setTime(10, 0));
    $scheduled = newsPost($this->fakhd, ['title' => 'خبر مجدول', 'publish_at' => now()->addHour(), 'expires_at' => now()->addDays(2)]);

    expect(newsRecords($this, route('community.entities.show', ['zain', 'f'])))->not->toContain("news-{$scheduled->id}");
    $this->get(route('community.news.show', ['zain', $scheduled->id]))->assertNotFound();
    $this->travel(2)->hours();
    expect(newsRecords($this, route('community.entities.show', ['zain', 'f'])))->toContain("news-{$scheduled->id}");
    $this->travel(3)->days();
    expect(newsRecords($this, route('community.entities.news', ['zain', 'f'])))->toBe([]);
    config(['community.expired_news' => 'labelled']);
    $this->get(route('community.entities.news', ['zain', 'f']))->assertSee('انتهى عرضه');
});

test('form times are read in the community time zone and stored in UTC', function () {
    $this->actingAs($this->admin)->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'news']), newsForm(['publish_at' => '2030-05-01T09:00']))->assertSessionHasNoErrors();

    expect(NewsPost::query()->sole()->publish_at->utc()->format('Y-m-d H:i'))->toBe('2030-05-01 06:00');
});

test('death notices need a family administrator, recorded consent and an explicit confirmation, and never change the person', function () {
    $fields = ['category' => 'death', 'title' => 'وفاة سالم الزين', 'deceased_name' => 'سالم الزين', 'deceased_person_id' => $this->deceased->id, 'death_date' => now()->format('Y-m-d'),
        'funeral_prayer' => 'بعد صلاة العصر في المسجد الكبير', 'condolence_place' => 'ديوان آل زين', 'contact_name' => 'أبو محمد', 'contact_phone' => '+962 7 9000 0000'];
    $store = route('management.entities.content.store', ['zain', $this->fakhd->id, 'news']);

    $this->actingAs($this->editor)->post($store, newsForm($fields, ['publication_status' => 'published']))->assertSessionHasErrors('publication_status');
    $this->post($store, newsForm($fields, ['publication_status' => 'submitted']))->assertSessionHasNoErrors();
    $post = NewsPost::query()->sole();
    $review = route('management.entities.content.review', ['zain', $this->fakhd->id, 'news', $post->id]);

    // An entity administrator may publish ordinary news but not a death notice.
    CommunityEntityManager::query()->create(['community_entity_id' => $this->fakhd->id, 'user_id' => $this->reader->id, 'role' => 'admin']);
    $this->actingAs($this->reader)->post($review, ['decision' => 'publish', 'sensitive_confirmation' => 1])->assertSessionHasErrors('publication_status');
    $this->actingAs($this->admin)->post($review, ['decision' => 'publish', 'sensitive_confirmation' => 1])->assertSessionHasErrors('family_consent');
    $post->update(['family_consent' => true]);
    $this->post($review, ['decision' => 'publish'])->assertSessionHasErrors('sensitive_confirmation');
    $this->post($review, ['decision' => 'publish', 'sensitive_confirmation' => 1])->assertSessionHasNoErrors();
    $post->refresh();
    expect($post->publication_status)->toBe('published')->and($post->sensitive_confirmed_by)->toBe($this->admin->id)->and($post->sensitive_confirmed_at)->not->toBeNull()
        ->and($this->deceased->fresh()->is_living)->toBeTrue();
    auth()->logout();

    // Details show with consent; the living genealogy record is not linked publicly; the phone stays hidden without its own consent.
    $this->get(route('community.news.show', ['zain', $post->id]))->assertOk()->assertSee('بعد صلاة العصر في المسجد الكبير')->assertSee('ديوان آل زين')
        ->assertDontSee(route('genealogy.person', ['zain', $this->deceased->id]), false)->assertDontSee('+962 7 9000 0000');
    $post->update(['contact_consent' => true]);
    $this->get(route('community.news.show', ['zain', $post->id]))->assertSee('+962 7 9000 0000');

    // After publication, only a family administrator may correct it.
    $notice = route('management.entities.content.notices.store', ['zain', $this->fakhd->id, 'news', $post->id]);
    $this->actingAs($this->reader)->post($notice, ['kind' => 'correction', 'message' => 'تعديل مكان العزاء'])->assertSessionHasErrors('kind');
    $this->actingAs($this->admin)->post($notice, ['kind' => 'correction', 'message' => 'نُقل العزاء إلى قاعة البلدية'])->assertSessionHasNoErrors();
    auth()->logout();
    $this->get(route('community.news.show', ['zain', $post->id]))->assertSee('تصحيح')->assertSee('نُقل العزاء إلى قاعة البلدية');
});

test('published news is corrected or cancelled through visible notices, never silently', function () {
    $post = newsPost($this->fakhd, ['title' => 'اجتماع عام', 'category' => 'meeting']);
    $notice = route('management.entities.content.notices.store', ['zain', $this->fakhd->id, 'news', $post->id]);

    $this->actingAs($this->editor)->post($notice, ['kind' => 'update', 'message' => 'تغيير'])->assertForbidden();
    $this->actingAs($this->admin)->post($notice, ['kind' => 'update', 'message' => 'تغيّر موعد الاجتماع إلى المساء'])->assertSessionHasNoErrors();
    $this->post($notice, ['kind' => 'cancellation', 'message' => 'أُلغي الاجتماع'])->assertSessionHasNoErrors();
    $this->post($notice, ['kind' => 'cancellation', 'message' => 'مرة أخرى'])->assertSessionHasErrors('kind');
    auth()->logout();

    $this->get(route('community.news.show', ['zain', $post->id]))->assertSee('data-cancelled', false)->assertSeeInOrder(['أُلغي الاجتماع', 'تغيّر موعد الاجتماع إلى المساء']);
    expect($post->notices()->pluck('kind')->all())->toBe(['cancellation', 'update'])
        ->and(AuditLog::query()->where('auditable_type', NewsPost::class)->where('auditable_id', $post->id)->orderBy('id')->pluck('action')->all())->toBe(['created', 'notice_update', 'notice_cancellation']);
});

test('drafts, member news and hidden owners stay private on lists and detail pages', function () {
    $draft = newsPost($this->fakhd, ['title' => 'مسودة'], ['publication_status' => 'draft']);
    $members = newsPost($this->fakhd, ['title' => 'خبر للأعضاء'], ['audience' => 'members']);
    $hiddenOwner = CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $this->clan->id, 'entity_type' => 'fakhd', 'name_ar' => 'مسودة', 'slug' => 'h', 'publication_status' => 'draft']);
    $orphan = newsPost($hiddenOwner, ['title' => 'خبر كيان غير منشور']);

    $this->get(route('community.news.show', ['zain', $draft->id]))->assertNotFound();
    $this->get(route('community.news.show', ['zain', $members->id]))->assertNotFound();
    $this->get(route('community.news.show', ['zain', $orphan->id]))->assertNotFound();
    $this->get(route('community.news.show', ['other', $members->id]))->assertNotFound();
    $this->actingAs($this->reader)->get(route('community.news.show', ['zain', $members->id]))->assertOk();
    $this->get(route('community.news.show', ['zain', $draft->id, 'preview' => 1]))->assertNotFound();
    $this->actingAs($this->editor)->get(route('community.news.show', ['zain', $draft->id, 'preview' => 1]))->assertOk()->assertSee('معاينة');
});

test('linked people appear only to viewers allowed to see them and media is reused, not copied', function () {
    $post = newsPost($this->fakhd, ['title' => 'تكريم'], ['people' => [$this->deceased->id, $this->historic->id]]);
    // The form's empty "add a person" picker is ignored rather than rejected.
    $this->actingAs($this->admin)->put(route('management.entities.content.update', ['zain', $this->fakhd->id, 'news', $post->id]), newsForm(['title' => 'تكريم'], ['publication_status' => 'published', 'people' => [$this->deceased->id, $this->historic->id, '']]))->assertSessionHasNoErrors();
    auth()->logout();
    $media = (new MediaItem)->forceFill(['owner_entity_id' => $this->fakhd->id, 'family_id' => $this->family->id, 'uuid' => (string) Str::uuid(), 'disk' => 'media', 'path' => 'x.jpg', 'display_path' => 'x-d.jpg',
        'original_name' => 'x.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1, 'checksum' => str_repeat('a', 64), 'category' => 'image', 'title' => 'صورة التكريم', 'alt_text' => 'صورة',
        'rights_status' => 'owned', 'publication_status' => 'published', 'audience' => 'public']);
    $media->save();
    $this->actingAs($this->admin)->post(route('management.entities.content.media.store', ['zain', $this->fakhd->id, 'news', $post->id]), ['media_item_id' => $media->id])->assertSessionHasNoErrors();
    auth()->logout();

    $this->get(route('community.news.show', ['zain', $post->id]))->assertSee('قاسم الزين')->assertDontSee('سالم الزين')->assertSee($media->url(), false);
    $this->actingAs($this->reader)->get(route('community.news.show', ['zain', $post->id]))->assertSee('سالم الزين')->assertDontSee(route('genealogy.person', ['zain', $this->deceased->id]), false);
    expect(MediaItem::query()->count())->toBe(1)->and($post->linkedPeople()->count())->toBe(2);
    $this->actingAs($this->admin)->delete(route('management.people.destroy', ['zain', $this->historic->id]))->assertSessionHasErrors();
});

test('review requests and decisions notify the right people in-app only', function () {
    $this->actingAs($this->editor)->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'news']), newsForm(['title' => 'مبادرة تشجير'], ['publication_status' => 'submitted']))->assertSessionHasNoErrors();
    $post = NewsPost::query()->sole();

    expect($this->admin->fresh()->notifications()->count())->toBe(1)
        ->and($this->admin->fresh()->notifications()->first()->data['title'])->toBe('محتوى بانتظار مراجعتك')
        ->and($this->reader->fresh()->notifications()->count())->toBe(0)->and($this->editor->fresh()->notifications()->count())->toBe(0);
    $this->actingAs($this->admin)->post(route('management.entities.content.review', ['zain', $this->fakhd->id, 'news', $post->id]), ['decision' => 'decline', 'review_note' => 'أضف صورة'])->assertSessionHasNoErrors();
    $notification = $this->editor->fresh()->notifications()->sole();
    expect($notification->data['body'])->toContain('أضف صورة')->and($notification->data['url'])->toContain('/manage/');

    $this->actingAs($this->editor)->get(route('notifications.index'))->assertOk()->assertSee('أُعيد المحتوى الذي قدمته إلى المسودة');
    $this->post(route('notifications.open', $notification->id))->assertRedirect($notification->data['url']);
    expect($notification->fresh()->read_at)->not->toBeNull();
    $this->actingAs($this->reader)->post(route('notifications.open', $notification->id))->assertNotFound();
});

test('other families cannot manage or read each other\'s news', function () {
    $post = newsPost($this->fakhd, ['title' => 'خبر'], ['audience' => 'members']);
    $other = Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other']);
    $outsider = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $other->id, 'user_id' => $outsider->id, 'role' => 'admin']);

    $this->actingAs($outsider)->get(route('management.entities.content.edit', ['zain', $this->fakhd->id, 'news', $post->id]))->assertForbidden();
    $this->get(route('community.news.show', ['zain', $post->id]))->assertNotFound();
    $this->post(route('management.entities.content.notices.store', ['zain', $this->fakhd->id, 'news', $post->id]), ['kind' => 'update', 'message' => 'x'])->assertForbidden();
});

test('publication discovery normalizes Arabic and features only eligible news once', function () {
    $featured = newsPost($this->fakhd, ['title' => 'إعلان أبناء العائلة', 'is_featured' => true]);
    newsPost($this->fakhd, ['title' => 'خبر المستقبل', 'is_featured' => true, 'publish_at' => now()->addDay()]);
    $response = $this->get(route('community.entities.news', ['zain', 'f', 'q' => 'اعلان ابناء']));
    $response->assertOk()->assertSee('الخبر البارز')->assertDontSee('خبر المستقبل');
    expect(substr_count($response->getContent(), 'data-record="news-'.$featured->id.'"'))->toBe(1);
});

test('an editor cannot feature a draft or bypass publication approval', function () {
    $this->actingAs($this->editor)->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'news']), newsForm(['is_featured' => true]))
        ->assertSessionHasErrors('is_featured');
    expect(NewsPost::query()->count())->toBe(0);
});

test('news discovery validates filter shapes and preserves date boundaries', function () {
    newsPost($this->fakhd, ['title' => 'خبر قديم', 'publish_at' => now()->subDays(10)]);
    $recent = newsPost($this->fakhd, ['title' => 'خبر حديث', 'publish_at' => now()->subHour()]);
    expect(newsRecords($this, route('community.entities.news', ['zain', 'f', 'from' => now()->subDay()->toDateString()])))->toBe(['news-'.$recent->id]);
    $this->getJson(route('community.entities.news', ['zain', 'f', 'q' => ['invalid']]))->assertUnprocessable();
    $this->getJson(route('community.entities.calendar', ['zain', 'f', 'month' => '2026-13']))->assertUnprocessable();
});

test('a news publisher may feature news without acquiring unrelated content privileges', function () {
    $this->seed(RolePermissionSeeder::class);
    $member = FamilyMembership::query()->where('user_id', $this->reader->id)->where('family_id', $this->family->id)->sole();
    $member->roleAssignments()->create(['role_id' => Role::query()->where('key', 'news-publisher')->sole()->id]);
    $this->actingAs($this->reader)->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'news']), newsForm(['is_featured' => true], ['publication_status' => 'published']))->assertSessionHasNoErrors();
    expect(NewsPost::query()->sole()->is_featured)->toBeTrue()
        ->and($this->reader->can('publish', [$this->fakhd, 'events']))->toBeTrue()
        ->and($this->reader->can('publish', [$this->fakhd, 'narratives']))->toBeFalse();
});

test('a public article does not disclose its unpublished linked event', function () {
    $event = CommunityEvent::query()->create(['owner_entity_id' => $this->fakhd->id, 'publication_status' => 'draft', 'category' => 'meeting', 'title' => 'موعد سري', 'starts_at' => now()->addDay(), 'timezone' => 'Asia/Amman']);
    $post = newsPost($this->fakhd, ['title' => 'خبر عام', 'event_id' => $event->id]);
    $this->get(route('community.news.show', ['zain', $post->id]))->assertOk()->assertDontSee('موعد سري')->assertDontSee('data-linked-event', false);
});
