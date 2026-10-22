<?php

use App\Models\AuditLog;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Services\Genealogy\CrossFamilyLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{0: Family, 1: User} */
function securityFamily(string $slug, string $role): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة '.$slug, 'slug' => $slug]);
    $family->familyTrees()->create(['name_ar' => 'شجرة', 'slug' => 'main', 'is_primary' => true]);
    $user = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $user->id, 'role' => $role]);

    return [$family, $user];
}

test('audit details for reviewer only and membership records are refused to readers and editors', function (string $type, bool $reviewersOnly, string $role) {
    [$family, $member] = securityFamily('audit', $role);
    $log = AuditLog::query()->create(['family_id' => $family->id, 'auditable_type' => $type, 'auditable_id' => 1, 'action' => 'created', 'reviewers_only' => $reviewersOnly, 'after' => ['email' => 'private@example.test']]);

    $this->actingAs($member)->get(route('management.audit.show', $log->id))->assertForbidden();
})->with([
    'reviewer only' => [Person::class, true],
    'membership' => [FamilyMembership::class, false],
    'invitation' => [FamilyInvitation::class, false],
])->with(['reader', 'editor']);

test('family administrators read restricted audit details while ordinary entries stay open to readers', function () {
    [$family, $admin] = securityFamily('audit', 'admin');
    $reader = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $reader->id, 'role' => 'reader']);
    $restricted = AuditLog::query()->create(['family_id' => $family->id, 'auditable_type' => FamilyInvitation::class, 'auditable_id' => 1, 'action' => 'created']);
    $ordinary = AuditLog::query()->create(['family_id' => $family->id, 'auditable_type' => Person::class, 'auditable_id' => 1, 'action' => 'created']);
    [, $outsider] = securityFamily('other', 'admin');

    $this->actingAs($admin)->get(route('management.audit.show', $restricted->id))->assertOk();
    $this->actingAs($reader)->get(route('management.audit.show', $ordinary->id))->assertOk();
    $this->actingAs($outsider)->get(route('management.audit.show', $ordinary->id))->assertForbidden();
});

test('the audit section list hides membership history from readers', function () {
    [$family, $reader] = securityFamily('audit', 'reader');
    AuditLog::query()->create(['family_id' => $family->id, 'auditable_type' => FamilyMembership::class, 'auditable_id' => 77, 'action' => 'created']);

    $this->actingAs($reader)->get(route('management.sections.index', ['audit', 'audit']))
        ->assertOk()->assertDontSee('FamilyMembership #77');
});

test('password reset gives the same answer for registered and unknown emails', function () {
    $user = User::factory()->create();

    $known = $this->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.test']);

    $known->assertSessionHasNoErrors();
    $unknown->assertSessionHasNoErrors()->assertSessionHas('status', $known->getSession()->get('status'));
});

test('non numeric record identifiers return not found instead of a server error', function () {
    [, $admin] = securityFamily('ids', 'admin');

    $this->actingAs($admin)->get('/manage/audit/abc')->assertNotFound();
    $this->get('/manage/families/ids/people/abc')->assertNotFound();
    $this->get('/manage/families/ids/sources/abc')->assertNotFound();
});

test('public genealogy endpoints are rate limited', function () {
    [$family] = securityFamily('limited', 'reader');

    foreach (range(1, 240) as $attempt) {
        $this->get(route('genealogy.search', [$family->slug, 'q' => 'x']));
    }

    $this->get(route('genealogy.search', [$family->slug, 'q' => 'x']))->assertTooManyRequests();
});

test('genealogy writes re-check only shared ancestry links of the changed family once per transaction', function () {
    $checked = [];
    $this->mock(CrossFamilyLinkService::class)->shouldReceive('current')->andReturnUsing(function (CrossFamilyLink $checkedLink) use (&$checked) {
        $checked[] = $checkedLink->id;

        return true;
    });
    $people = [];
    foreach (['left', 'right', 'unrelated'] as $slug) {
        [$family] = securityFamily($slug, 'admin');
        $people[$slug] = $family->primaryTree->people()->create(['first_name_ar' => $slug, 'full_name_ar' => $slug]);
    }
    $link = CrossFamilyLink::query()->create([
        'left_person_id' => $people['left']->id, 'right_person_id' => $people['right']->id,
        'left_family_id' => $people['left']->familyTree->family_id, 'right_family_id' => $people['right']->familyTree->family_id,
        'kind' => 'same_person', 'status' => 'approved', 'claim' => 'اختبار',
    ]);
    $checked = [];

    $people['unrelated']->update(['full_name_ar' => 'تعديل']);
    expect($checked)->toBe([]);

    DB::transaction(function () use ($people) {
        foreach (range(1, 3) as $index) {
            $people['left']->update(['full_name_ar' => 'تعديل '.$index]);
        }
    });
    expect($checked)->toBe([$link->id]);
});
