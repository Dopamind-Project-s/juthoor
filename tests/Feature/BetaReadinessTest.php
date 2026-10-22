<?php

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\User;
use App\Notifications\FamilyInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function betaContext(): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة اصطناعية', 'slug' => 'synthetic', 'is_active' => true]);
    $tree = $family->familyTrees()->create(['name_ar' => 'الأولى', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $second = $family->familyTrees()->create(['name_ar' => 'الثانية', 'slug' => 'second', 'is_primary' => false, 'is_active' => true]);
    $manager = User::factory()->create();
    $family->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);

    return compact('family', 'tree', 'second', 'manager');
}

function betaPerson(FamilyTree $tree, string $name): Person
{
    return $tree->people()->create(['first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
}

function betaSuper(): User
{
    $user = User::factory()->create();
    $user->forceFill(['is_super_admin' => true])->save();

    return $user->refresh();
}

test('a new family and manager invitation work without any seeded people', function () {
    Notification::fake();
    $super = betaSuper();

    $this->actingAs($super)->post(route('site-admin.families.store'), ['name_ar' => 'عائلة جديدة تمامًا', 'slug' => 'brand-new', 'tree_name_ar' => 'شجرة جديدة', 'manager_email' => 'new-manager@example.test'])->assertRedirect()->assertSessionHasNoErrors();
    $family = Family::query()->where('slug', 'brand-new')->sole();
    $tree = $family->primaryTree;
    $this->get(route('management.families.show', $family->slug))->assertSee('حفظ الجذر');
    $this->getJson(route('genealogy.graph', $family->slug))->assertJsonCount(0, 'nodes');
    $this->post(route('management.people.store', $family->slug), ['first_name_ar' => 'جذر', 'full_name_ar' => 'جذر اصطناعي', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => '0'])->assertRedirect()->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseCount('people', 1);
    $root = $tree->people()->sole();
    $this->put(route('management.trees.update', [$family->slug, $tree->id, 'tree' => 'main']), ['root_person_id' => $root->id])->assertRedirect()->assertSessionHasNoErrors();
    $this->getJson(route('genealogy.graph', $family->slug))->assertJsonFragment(['name' => 'جذر اصطناعي']);

    Notification::assertSentOnDemand(FamilyInvitationNotification::class, fn ($notification) => $notification->invitation->role === 'admin');
    $this->assertDatabaseHas('audit_logs', ['family_id' => $family->id, 'action' => 'invited']);
    expect($tree->fresh()->root_person_id)->toBe($root->id);
});

test('selected trees scope person creation lookups graph search branches and relationship writes', function () {
    $context = betaContext();
    ['family' => $family, 'tree' => $first, 'second' => $second, 'manager' => $manager] = $context;
    $firstRoot = betaPerson($first, 'جذر الشجرة الأولى');
    $first->update(['root_person_id' => $firstRoot->id]);
    $secondRoot = betaPerson($second, 'جذر الشجرة الثانية');
    $second->update(['root_person_id' => $secondRoot->id]);
    $parameters = [$family->slug, 'tree' => 'second'];

    $this->actingAs($manager)->post(route('management.people.store', $parameters), ['first_name_ar' => 'طفل', 'full_name_ar' => 'طفل الشجرة الثانية', 'status' => 'historical', 'is_living' => '0', 'verification_status' => 'verified'])->assertRedirect()->assertSessionHasNoErrors();
    $child = $second->people()->where('first_name_ar', 'طفل')->sole();
    $this->post(route('management.relationships.store', $parameters), ['person_id' => $child->id, 'father_id' => $secondRoot->id, 'verification_status' => 'verified'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('management.relationships.store', $parameters), ['person_id' => $child->id, 'father_id' => $firstRoot->id, 'verification_status' => 'verified'])->assertNotFound();
    $this->post(route('management.branches.store', $parameters), ['name_ar' => 'فرع الثانية', 'slug' => 'second-branch', 'root_person_id' => $child->id, 'verification_status' => 'verified', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('management.families.show', $parameters))->assertSee('طفل الشجرة الثانية')->assertDontSee('جذر الشجرة الأولى');
    $this->getJson(route('management.lookups.people', $parameters))->assertJsonCount(2, 'data')->assertJsonMissing(['id' => $firstRoot->id]);
    $this->getJson(route('management.lookups.branches', $parameters))->assertJsonFragment(['text' => 'فرع الثانية']);
    $this->getJson(route('genealogy.graph', $parameters))->assertJsonCount(2, 'nodes')->assertJsonPath('tree.id', $second->id);
    $this->getJson(route('genealogy.search', [...$parameters, 'q' => 'جذر']))->assertJsonCount(1, 'data')->assertJsonFragment(['id' => $secondRoot->id]);
    $this->get(route('genealogy.person', [$family->slug, $firstRoot->id, 'tree' => 'second']))->assertNotFound();
    $this->getJson(route('genealogy.graph', [$family->slug, 'tree' => 'unknown']))->assertNotFound();
    expect($second->personRelationships()->count())->toBe(1);
    expect($first->people()->count())->toBe(1);
});

test('only managers can set a root and roots cannot come from another tree or have a confirmed father', function () {
    ['family' => $family, 'tree' => $tree, 'second' => $second, 'manager' => $manager] = betaContext();
    $parent = betaPerson($tree, 'الأب');
    $child = betaPerson($tree, 'الابن');
    $foreign = betaPerson($second, 'أجنبي');
    $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $parent->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $reader = User::factory()->create();
    $family->memberships()->create(['user_id' => $reader->id, 'role' => 'reader']);
    $url = route('management.trees.update', [$family->slug, $tree->id]);

    $this->actingAs($reader)->put($url, ['root_person_id' => $parent->id])->assertForbidden();
    $this->actingAs($manager)->put($url, ['root_person_id' => $foreign->id])->assertSessionHasErrors('root_person_id');
    $this->put($url, ['root_person_id' => $child->id])->assertSessionHasErrors('root_person_id');
    expect($tree->fresh()->root_person_id)->toBeNull();
    $this->put($url, ['root_person_id' => $parent->id])->assertRedirect()->assertSessionHasNoErrors();
    expect($tree->fresh()->root_person_id)->toBe($parent->id);
});

test('making a second tree primary preserves a single primary and its root', function () {
    ['family' => $family, 'tree' => $first, 'second' => $second] = betaContext();
    $root = betaPerson($second, 'جذر الثانية');
    $second->update(['root_person_id' => $root->id]);
    $super = betaSuper();

    $this->actingAs($super)->put(route('site-admin.trees.update', [$family->id, $first->id]), ['name_ar' => $first->name_ar, 'is_active' => 1])->assertSessionHasErrors('is_primary');
    $this->put(route('site-admin.trees.update', [$family->id, $second->id]), ['name_ar' => $second->name_ar, 'is_active' => 1, 'is_primary' => 1])->assertRedirect()->assertSessionHasNoErrors();
    $this->getJson(route('genealogy.graph', $family->slug))->assertJsonPath('root_id', $root->id);
    expect($family->familyTrees()->primary()->count())->toBe(1);
    expect($first->fresh()->is_primary)->toBeFalse();
});

test('successive child batches reach all children without repeating nodes or edges', function () {
    ['family' => $family, 'tree' => $tree] = betaContext();
    $root = betaPerson($tree, 'جذر كبير');
    $tree->update(['root_person_id' => $root->id]);
    foreach (range(1, 35) as $number) {
        $child = betaPerson($tree, 'شخص اصطناعي '.$number);
        $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $root->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    }
    $ids = [];
    $edges = [];
    $after = 0;
    for ($page = 0; $page < 4; $page++) {
        $payload = $this->getJson(route('genealogy.graph', [$family->slug, 'root' => $root->id, 'depth' => 1, 'limit' => 10, 'after' => $after]))->assertOk()->json();
        $batch = array_column($payload['edges'], 'child_id');
        expect(array_intersect($ids, $batch))->toBeEmpty();
        $ids = array_merge($ids, $batch);
        $edges = array_merge($edges, array_column($payload['edges'], 'id'));
        $after = max($ids);
    }

    expect($ids)->toHaveCount(35);
    expect(array_unique($edges))->toHaveCount(35);
    expect($payload['nodes'][0]['has_more_children'])->toBeFalse();
    expect($payload['meta']['complete'])->toBeTrue();
});

test('a child with only hidden descendants has no public load more indicator', function () {
    ['family' => $family, 'tree' => $tree] = betaContext();
    $root = betaPerson($tree, 'جذر');
    $tree->update(['root_person_id' => $root->id]);
    $living = betaPerson($tree, 'شخص حي يجب حجبه');
    $living->update(['is_living' => true]);
    $tree->personRelationships()->create(['person_id' => $living->id, 'related_person_id' => $root->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);

    $this->getJson(route('genealogy.graph', $family->slug))->assertJsonCount(1, 'nodes')->assertJsonPath('nodes.0.has_more_children', false)->assertJsonPath('meta.complete', true);
    $this->get(route('genealogy.person', [$family->slug, $living->id]))->assertNotFound();
});

test('invitation email is queued with retries and stale queued links are suppressed after resend', function () {
    ['family' => $family, 'manager' => $manager] = betaContext();
    $this->travelTo(now()->startOfSecond());
    Queue::fake([SendQueuedNotifications::class]);
    $old = null;

    $this->actingAs($manager)->post(route('invitations.store', $family->slug), ['email' => 'queued@example.test', 'role' => 'editor'])->assertRedirect()->assertSessionHasNoErrors();
    Queue::assertPushed(SendQueuedNotifications::class, function ($job) use (&$old) {
        $old = $job->notification;

        return $job->tries === 4 && $job->backoff() === [30, 120, 300];
    });
    $invitation = $family->invitations()->sole();
    $this->post(route('invitations.resend', [$family->slug, $invitation->id]))->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('invitations.show', $old->token))->assertNotFound();
    expect($old->shouldSend(new stdClass, 'mail'))->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'resent', 'auditable_id' => $invitation->id]);
});

test('resending an expired invitation issues a working token and invalidates the old registration token', function () {
    ['family' => $family, 'manager' => $manager] = betaContext();
    Notification::fake();
    $this->travelTo(now()->startOfSecond());
    $invitation = $family->invitations()->create(['email' => 'renewed@example.test', 'role' => 'admin', 'invited_by' => $manager->id, 'token_hash' => hash('sha256', 'old-token'), 'expires_at' => now()->subDay()]);
    $token = null;

    $this->actingAs($manager)->post(route('invitations.resend', [$family->slug, $invitation->id]))->assertRedirect()->assertSessionHasNoErrors();
    Notification::assertSentOnDemand(FamilyInvitationNotification::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });
    auth()->logout();
    $this->get(route('invitations.register', 'old-token'))->assertNotFound();
    $this->get(route('invitations.register', $token))->assertSee('renewed@example.test');
    expect($invitation->fresh()->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
});

test('cancelled or accepted invitations cannot be resent', function (string $state) {
    ['family' => $family, 'manager' => $manager] = betaContext();
    Notification::fake();
    $invitation = $family->invitations()->create(['email' => 'closed@example.test', 'role' => 'reader', 'token_hash' => hash('sha256', 'closed'), 'expires_at' => now()->addDay(), $state => now()]);

    $this->actingAs($manager)->post(route('invitations.resend', [$family->slug, $invitation->id]))->assertSessionHasErrors('invitation');
    Notification::assertNothingSent();
    expect($invitation->fresh()->token_hash)->toBe(hash('sha256', 'closed'));
})->with(['cancelled_at', 'accepted_at']);

test('disabled families refuse invitation acceptance registration private requests and writes from current members', function () {
    ['family' => $family, 'manager' => $manager] = betaContext();
    $invitee = User::factory()->create(['email' => 'disabled-family@example.test']);
    $family->invitations()->create(['email' => $invitee->email, 'role' => 'editor', 'token_hash' => hash('sha256', 'disabled'), 'expires_at' => now()->addDay()]);
    $family->invitations()->create(['email' => 'new-disabled@example.test', 'role' => 'editor', 'token_hash' => hash('sha256', 'new-disabled'), 'expires_at' => now()->addDay()]);
    $family->update(['is_active' => false]);

    $this->actingAs($manager)->get(route('management.families.show', $family->slug))->assertForbidden();
    $this->getJson(route('genealogy.graph', [$family->slug, 'private' => 1]))->assertNotFound();
    $this->post(route('management.people.store', $family->slug), ['first_name_ar' => 'حي', 'full_name_ar' => 'حي', 'status' => 'active', 'verification_status' => 'verified'])->assertForbidden();
    $this->actingAs($invitee)->post(route('invitations.accept', 'disabled'))->assertSessionHasErrors('invitation');
    auth()->logout();
    $this->post(route('invitations.register.store', 'new-disabled'), ['name' => 'جديد', 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password'])->assertGone();
    $this->assertDatabaseMissing('family_memberships', ['family_id' => $family->id, 'user_id' => $invitee->id]);
    $this->assertDatabaseMissing('users', ['email' => 'new-disabled@example.test']);
});

test('disabling a signed in user invalidates private requests and their database sessions and audit hides credentials', function () {
    ['family' => $family] = betaContext();
    $super = betaSuper();
    $reader = User::factory()->create();
    $family->memberships()->create(['user_id' => $reader->id, 'role' => 'reader']);
    DB::table('sessions')->insert(['id' => 'existing-session', 'user_id' => $reader->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($super)->put(route('site-admin.users.update', $reader->id), ['is_active' => 0])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('sessions', ['user_id' => $reader->id]);
    $this->actingAs($reader)->getJson(route('genealogy.graph', [$family->slug, 'private' => 1]))->assertForbidden();
    $this->assertGuest();
    $log = AuditLog::query()->where('auditable_type', User::class)->where('auditable_id', $reader->id)->latest()->firstOrFail();
    expect($log->before)->not->toHaveKeys(['password', 'remember_token']);
});

test('last active family manager cannot be disabled deleted or removed when other managers are inactive', function () {
    ['family' => $family, 'manager' => $manager] = betaContext();
    $super = betaSuper();
    $inactive = User::factory()->create();
    $inactive->forceFill(['is_active' => false])->save();
    $family->memberships()->create(['user_id' => $inactive->id, 'role' => 'admin']);
    $membership = $manager->familyMemberships()->sole();

    $this->actingAs($super)->put(route('site-admin.users.update', $manager->id), ['is_active' => 0])->assertSessionHasErrors('user');
    $this->delete(route('site-admin.users.destroy', $manager->id))->assertSessionHasErrors('user');
    $this->delete(route('management.memberships.destroy', [$family->slug, $membership->id]))->assertSessionHasErrors('role');
    expect($manager->fresh()->is_active)->toBeTrue();
    $this->assertModelExists($membership);
});

test('accepting another invitation never downgrades an existing family administrator', function () {
    ['family' => $family, 'manager' => $manager] = betaContext();
    $family->invitations()->create(['email' => $manager->email, 'role' => 'reader', 'token_hash' => hash('sha256', 'downgrade'), 'expires_at' => now()->addDay()]);

    $this->actingAs($manager)->post(route('invitations.accept', 'downgrade'))->assertSessionHasErrors('invitation');
    expect($manager->roleFor($family))->toBe('admin');
});

test('private previews retain tree scope and pending living people stay hidden from anonymous visitors', function () {
    ['family' => $family, 'second' => $tree, 'manager' => $manager] = betaContext();
    $root = betaPerson($tree, 'شخص حي خاص');
    $root->update(['status' => 'active', 'is_living' => true, 'verification_status' => 'pending_review']);
    $tree->update(['root_person_id' => $root->id]);
    $child = betaPerson($tree, 'ابن خاص قيد المراجعة');
    $child->update(['verification_status' => 'pending_review']);
    $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $root->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'pending_review']);
    $parameters = [$family->slug, 'tree' => 'second', 'private' => 1];

    $this->get(route('genealogy.tree', $parameters))->assertForbidden();
    $this->actingAs($manager)->get(route('genealogy.tree', $parameters))->assertSee('معاينة خاصة');
    $this->getJson(route('genealogy.graph', $parameters))->assertJsonPath('nodes.0.name', 'شخص حي خاص')
        ->assertJsonPath('nodes.0.url', route('genealogy.person', [$family->slug, $root->id, 'tree' => 'second', 'private' => 1]));
    $this->get(route('genealogy.person', [$family->slug, $root->id, 'tree' => 'second', 'private' => 1]))->assertSee('شخص حي خاص')->assertSee('ابن خاص قيد المراجعة')->assertSee('قيد المراجعة');
    auth()->logout();
    $this->get(route('genealogy.person', [$family->slug, $root->id, 'tree' => 'second']))->assertNotFound();
});

test('verification and password reset mail are queued and suppressed after account deactivation', function () {
    $user = User::factory()->unverified()->create();
    Queue::fake([SendQueuedNotifications::class]);
    $notifications = [];

    $this->actingAs($user)->post(route('verification.send'))->assertRedirect()->assertSessionHasNoErrors();
    auth()->logout();
    $this->post(route('password.email'), ['email' => $user->email])->assertRedirect()->assertSessionHasNoErrors();
    Queue::assertPushed(SendQueuedNotifications::class, 2);
    Queue::assertPushed(SendQueuedNotifications::class, function ($job) use (&$notifications) {
        $notifications[] = $job->notification;

        return $job->tries === 4;
    });
    $user->forceFill(['is_active' => false])->save();
    foreach ($notifications as $notification) {
        expect($notification->shouldSend($user, 'mail'))->toBeFalse();
    }
});

test('a replacement invitation cancels previous pending tokens and closed invitations show no accept form', function () {
    ['family' => $family, 'manager' => $manager] = betaContext();
    Notification::fake();
    $previous = $family->invitations()->create(['email' => 'replacement@example.test', 'role' => 'admin', 'token_hash' => hash('sha256', 'previous'), 'expires_at' => now()->addDay()]);

    $this->actingAs($manager)->post(route('invitations.store', $family->slug), ['email' => 'replacement@example.test', 'role' => 'reader'])->assertRedirect()->assertSessionHasNoErrors();
    expect($previous->fresh()->cancelled_at)->not->toBeNull();
    $this->get(route('invitations.show', 'previous'))->assertSee('هذه الدعوة غير متاحة')->assertDontSee('قبول الدعوة');
    $this->assertDatabaseHas('audit_logs', ['action' => 'cancelled', 'auditable_id' => $previous->id]);
});
