<?php

use App\Models\AuditLog;
use App\Models\CommunityEntity;
use App\Models\CommunityEvent;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\NewsPost;
use App\Models\User;
use App\Services\Community\CommunityContentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2030-03-10 09:00', 'UTC'));
    $this->family = Family::query()->create(['name_ar' => 'عائلة زين', 'slug' => 'zain']);
    $entity = fn (string $slug, string $type, ?CommunityEntity $parent) => CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $parent?->id, 'entity_type' => $type, 'name_ar' => 'كيان '.$slug, 'slug' => $slug, 'publication_status' => 'published']);
    $this->clan = $entity('clan', 'clan', null);
    $this->fakhd = $entity('f', 'fakhd', $this->clan);
    $this->sibling = $entity('g', 'fakhd', $this->clan);
    foreach (['admin', 'editor'] as $role) {
        $user = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $user->id, 'role' => $role]);
        $this->{$role} = $user->fresh();
    }
});

/** Creates an event as the family administrator; times are wall clock in the event's zone. */
function communityEvent(CommunityEntity $owner, array $fields, array $governance = [], ?User $creator = null): Model
{
    $creator ??= User::query()->whereHas('familyMemberships', fn ($q) => $q->where('role', 'admin')->where('family_id', $owner->family_id))->orderBy('id')->firstOrFail();

    return app(CommunityContentService::class)->save($creator, $owner, new CommunityEvent, CommunityEvent::prepareContentFields(['category' => 'gathering', 'timezone' => 'Asia/Amman', ...$fields]),
        ['publication_status' => 'published', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [], 'audiences' => [], ...$governance]);
}

/** @return array<int, string> */
function eventRecords(object $test, string $url): array
{
    preg_match_all('/data-(?:record="events-|calendar-event=")(\d+)"/', $test->get($url)->assertOk()->getContent(), $matches);

    return array_values(array_unique($matches[1]));
}

test('events store UTC with their own time zone and display local wall time', function () {
    $this->actingAs($this->admin)->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'events']), [
        'category' => 'wedding', 'title' => 'زفاف محمد', 'timezone' => 'Asia/Amman', 'starts_at' => '2030-03-20T19:30', 'ends_at' => '2030-03-20T23:00',
        'location_name' => 'قاعة الأفراح', 'city' => 'إربد', 'latitude' => '32.55', 'longitude' => '35.85',
        'publication_status' => 'published', 'verification_status' => 'pending_review', 'audience' => 'public',
    ])->assertSessionHasNoErrors();
    $event = CommunityEvent::query()->sole();
    auth()->logout();

    expect($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2030-03-20 16:30')->and($event->display_status)->toBe('scheduled');
    $this->get(route('community.events.show', ['zain', $event->id]))->assertOk()->assertSee('2030-03-20 19:30')->assertSee('Asia/Amman')
        ->assertSee('https://www.google.com/maps/dir/?api=1&amp;destination=32.55%2C35.85', false);
    $this->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'events']), [])->assertRedirect(route('login'));
    $this->actingAs($this->admin)->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'events']), ['category' => 'wedding', 'title' => 'x', 'timezone' => 'Mars/Base', 'starts_at' => '2030-03-20T19:30', 'publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public'])
        ->assertSessionHasErrors('timezone');
});

test('the calendar lists upcoming and past events by month, category and scope without siblings', function () {
    $own = communityEvent($this->fakhd, ['title' => 'لقاء الفخذ', 'starts_at' => '2030-03-15 18:00']);
    $graduation = communityEvent($this->fakhd, ['title' => 'حفل تخرج', 'category' => 'graduation', 'starts_at' => '2030-04-02 17:00']);
    $past = communityEvent($this->fakhd, ['title' => 'لقاء سابق', 'starts_at' => '2030-02-01 18:00']);
    $clanWide = communityEvent($this->clan, ['title' => 'اجتماع العشيرة السنوي', 'category' => 'meeting', 'starts_at' => '2030-03-25 18:00'], ['audiences' => [$this->clan->id]]);
    $sibling = communityEvent($this->sibling, ['title' => 'مناسبة الفخذ المجاور', 'starts_at' => '2030-03-16 18:00']);
    $calendar = fn (array $query = []) => eventRecords($this, route('community.entities.calendar', ['zain', 'f', ...$query]));

    expect($calendar())->toContain((string) $own->id, (string) $graduation->id, (string) $past->id, (string) $clanWide->id)->not->toContain((string) $sibling->id)
        ->and($calendar(['category' => 'graduation']))->toBe([(string) $graduation->id])
        ->and($calendar(['scope' => 'direct']))->not->toContain((string) $clanWide->id);
    $march = $this->get(route('community.entities.calendar', ['zain', 'f', 'month' => '2030-03']))->getContent();
    expect($march)->toContain('data-day="2030-03-15"')->toContain('data-calendar-event="'.$own->id.'"')->not->toContain('data-calendar-event="'.$graduation->id.'"');
    $page = $this->get(route('community.entities.calendar', ['zain', 'f']))->getContent();
    expect(explode('data-past', $page)[1])->toContain('لقاء سابق')->and(explode('data-past', $page)[0])->not->toContain('data-record="events-'.$past->id.'"');
    // The portal section shows only upcoming events.
    expect(eventRecords($this, route('community.entities.show', ['zain', 'f'])))->not->toContain((string) $past->id);
});

test('schedule changes keep the replaced values, stay visible and notify', function () {
    $event = communityEvent($this->fakhd, ['title' => 'لقاء عائلي', 'starts_at' => '2030-03-15 18:00', 'location_name' => 'الديوان القديم'], ['publication_status' => 'draft'], $this->editor->fresh());
    $event->update(['publication_status' => 'published']);
    $notice = route('management.entities.content.notices.store', ['zain', $this->fakhd->id, 'events', $event->id]);

    $this->actingAs($this->editor)->post($notice, ['kind' => 'rescheduled', 'message' => 'x', 'starts_at' => '2030-03-16T18:00'])->assertForbidden();
    $this->actingAs($this->admin)->post($notice, ['kind' => 'rescheduled', 'message' => 'نُقل اللقاء إلى السبت', 'starts_at' => '2030-03-16T18:00'])->assertSessionHasNoErrors();
    $this->post($notice, ['kind' => 'relocated', 'message' => 'تغير المكان', 'location_name' => 'الديوان الجديد'])->assertSessionHasNoErrors();
    $this->post($notice, ['kind' => 'postponed', 'message' => 'تأجيل لظروف طارئة'])->assertSessionHasNoErrors();
    $event->refresh();

    expect($event->event_status)->toBe('postponed')->and($event->localStart()->format('Y-m-d H:i'))->toBe('2030-03-16 18:00')->and($event->location_name)->toBe('الديوان الجديد')
        ->and($event->notices()->where('kind', 'rescheduled')->sole()->previous['starts_at'])->toStartWith('2030-03-15T15:00')
        ->and($event->notices()->where('kind', 'relocated')->sole()->previous['location_name'])->toBe('الديوان القديم')
        ->and($this->editor->fresh()->notifications()->count())->toBe(3)
        ->and(AuditLog::query()->where('auditable_type', CommunityEvent::class)->where('auditable_id', $event->id)->orderBy('id')->pluck('action')->all())->toBe(['created', 'notice_rescheduled', 'notice_relocated', 'notice_postponed']);

    $this->post($notice, ['kind' => 'cancellation', 'message' => 'أُلغي اللقاء'])->assertSessionHasNoErrors();
    auth()->logout();
    $this->get(route('community.events.show', ['zain', $event->id]))->assertSee('data-event-status="cancelled"', false)->assertSeeInOrder(['أُلغي اللقاء', 'تأجيل لظروف طارئة', 'تغير المكان', 'نُقل اللقاء إلى السبت'])
        ->assertDontSee('data-directions', false);
    $this->travelTo(CarbonImmutable::parse('2030-04-01'));
    expect($event->fresh()->display_status)->toBe('cancelled');
});

test('an announcement generated from an event references it instead of copying it', function () {
    $event = communityEvent($this->fakhd, ['title' => 'يوم التطوع', 'category' => 'initiative', 'description' => 'تنظيف الحديقة العامة', 'starts_at' => '2030-03-18 08:00'], ['subjects' => [$this->fakhd->id, $this->sibling->id]]);

    $this->actingAs($this->editor)->post(route('management.entities.content.announce', ['zain', $this->fakhd->id, 'events', $event->id]))->assertRedirect();
    $post = NewsPost::query()->sole();
    expect($post->event_id)->toBe($event->id)->and($post->publication_status)->toBe('draft')->and($post->category)->toBe('announcement')
        ->and($post->entityLinks()->pluck('community_entity_id')->sort()->values()->all())->toBe([$this->fakhd->id, $this->sibling->id])
        ->and(CommunityEvent::query()->count())->toBe(1);

    $this->get(route('community.events.show', ['zain', $event->id]))->assertDontSee('إعلان: يوم التطوع');
    $post->update(['publication_status' => 'published']);
    auth()->logout();
    $this->get(route('community.events.show', ['zain', $event->id]))->assertSee('إعلان: يوم التطوع');
    $this->get(route('community.news.show', ['zain', $post->id]))->assertSee('data-linked-event', false)->assertSee('يوم التطوع');
});

test('finished scheduled events show as completed without changing the record', function () {
    $event = communityEvent($this->fakhd, ['title' => 'أمسية', 'starts_at' => '2030-03-01 18:00', 'ends_at' => '2030-03-01 21:00']);

    expect($event->display_status)->toBe('completed')->and($event->fresh()->event_status)->toBe('scheduled');
    $this->get(route('community.events.show', ['zain', $event->id]))->assertSee('data-event-status="completed"', false);
});

test('calendar list search and ongoing state use recorded times without rewriting event history', function () {
    $event = communityEvent($this->fakhd, ['title' => 'إنجاز أبناء الفخذ', 'starts_at' => '2030-03-10T11:00', 'ends_at' => '2030-03-10T14:00']);
    expect($event->display_status)->toBe('ongoing')->and($event->fresh()->event_status)->toBe('scheduled');
    $url = route('community.entities.calendar', ['zain', 'f', 'month' => '2030-03', 'layout' => 'list', 'status' => 'ongoing', 'q' => 'انجاز ابناء']);
    $this->get($url)->assertOk()->assertSee('جارية الآن')->assertSee('إنجاز أبناء الفخذ')->assertViewHas('listEvents', fn ($events) => $events->total() === 1);
    $this->get(route('community.entities.calendar', ['zain', 'g', 'layout' => 'list', 'month' => '2030-03']))->assertDontSee('إنجاز أبناء الفخذ');
});
