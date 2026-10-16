<?php

use App\Models\AuditLog;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Genealogy\CrossFamilyLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function sharedFixture(): array
{
    $super = User::factory()->create();
    $super->forceFill(['is_super_admin' => true, 'is_active' => true])->save();
    $admin = User::factory()->create();
    $otherAdmin = User::factory()->create();
    $people = [];
    foreach (['one', 'two'] as $slug) {
        $family = Family::query()->create(['slug' => $slug, 'name_ar' => 'عائلة '.$slug, 'is_active' => true]);
        $tree = $family->familyTrees()->create(['slug' => 'main', 'name_ar' => 'الرئيسية', 'is_primary' => true, 'is_active' => true]);
        $person = $tree->people()->create(['first_name_ar' => 'آدم', 'full_name_ar' => 'آدم متشابه', 'status' => 'historical', 'is_living' => false, 'verification_status' => 'verified']);
        $tree->update(['root_person_id' => $person->id]);
        $people[] = $person;
        $family->memberships()->create(['user_id' => ($slug === 'one' ? $admin : $otherAdmin)->id, 'role' => 'admin']);
    }

    return [$super, $admin, $otherAdmin, ...$people];
}

function sharedProposal(Person $left, Person $right, array $extra = []): array
{
    return array_replace(['family' => $left->familyTree->family->slug, 'left_person_id' => $left->id, 'right_person_id' => $right->id, 'kind' => 'same_person', 'claim' => 'مطابقة تجريبية صريحة وليست دعوى تاريخية حقيقية', 'source_title' => 'مرجع اختباري', 'source_url' => 'https://example.org/synthetic-record', 'reference' => 'SYNTHETIC-001', 'locator' => 'صفحة ١٢ سجل ٣', 'evidence' => 'دليل اصطناعي لاختبار سير العمل فقط.'], $extra);
}

function approveShared(CrossFamilyLink $link, User $super): void
{
    app(CrossFamilyLinkService::class)->review($link, $super, ['decision' => 'approved', 'reason' => 'دليل اصطناعي كاف لاختبار المراجعة فقط', 'evidence_confirmed' => true]);
}

test('family admins submit and track scoped source-backed proposals without merging records', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $before = Person::query()->get()->toJson();
    $this->actingAs($admin)->get(route('cross-links.create', ['family' => 'one']))->assertOk();
    $this->post(route('cross-links.store'), sharedProposal($left, $right))->assertSessionHasNoErrors()->assertRedirect();
    $link = CrossFamilyLink::query()->sole();
    expect($link->status)->toBe('pending_review')->and($link->citations()->count())->toBe(1)->and(Person::query()->get()->toJson())->toBe($before);
    $this->get(route('cross-links.index', ['family' => 'one']))->assertOk();
    $this->get(route('cross-links.show', $link))->assertOk()->assertSee('مرجع اختباري');
    $this->post(route('cross-links.store'), sharedProposal($left, $right))->assertSessionHasErrors('right_person_id');
    $this->post(route('cross-links.review', $link), ['decision' => 'approved', 'reason' => 'سبب مقدم من شخص غير مخول'])->assertForbidden();
    $this->actingAs($other)->get(route('cross-links.show', $link))->assertOk();
    $this->get(route('cross-links.index', ['family' => 'one']))->assertForbidden();
    $this->post(route('cross-links.store'), sharedProposal($left, $right))->assertForbidden();
    foreach (['reader', 'editor'] as $role) {
        $user = User::factory()->create();
        $left->familyTree->family->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        $this->actingAs($user)->get(route('cross-links.show', $link))->assertForbidden();
        $this->post(route('cross-links.store'), sharedProposal($left, $right))->assertForbidden();
    }
    $this->actingAs($super)->get(route('cross-links.index'))->assertOk();
});

test('only explicit reviewed evidence publishes a match and similar names do not connect trees', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $this->get(route('shared-ancestry.index'))->assertOk()->assertSee('لم تُثبت صلة مشتركة');
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right))->assertSessionHasNoErrors();
    $link = CrossFamilyLink::query()->sole();
    $this->get(route('shared-ancestry.index'))->assertDontSee('مطابقة تجريبية صريحة');
    $this->actingAs($super)->post(route('cross-links.review', $link), ['decision' => 'approved', 'reason' => 'سبب لا يؤكد فحص المصادر'])->assertSessionHasErrors('decision');
    $this->post(route('cross-links.review', $link), ['decision' => 'approved', 'reason' => '', 'evidence_confirmed' => 1])->assertSessionHasErrors('reason');
    approveShared($link, $super);
    expect($link->fresh()->reviewed_by)->toBe($super->id)->and($link->fresh()->reviewed_at)->not->toBeNull();
    $this->get(route('shared-ancestry.index'))->assertOk()->assertSee('مطابقة تجريبية صريحة')->assertSee('SYNTHETIC-001')->assertSee('صفحة ١٢ سجل ٣');
    expect(Person::query()->count())->toBe(2)->and(PersonRelationship::query()->count())->toBe(0);
});

test('rejection and disputes require reasons and never alter a family tree', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right));
    $link = CrossFamilyLink::query()->sole();
    $before = Person::query()->get()->toJson();
    foreach (['rejected', 'disputed'] as $status) {
        $this->actingAs($super)->post(route('cross-links.review', $link), ['decision' => $status, 'reason' => 'الأدلة المقدمة لا تثبت هذا الارتباط'])->assertSessionHasNoErrors();
        expect($link->fresh()->status)->toBe($status)->and(Person::query()->get()->toJson())->toBe($before);
        $this->get(route('shared-ancestry.index'))->assertDontSee('مطابقة تجريبية صريحة');
    }
});

test('living restricted and foreign pending records cannot be exposed by proposals or approval', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    foreach ([['is_living' => true], ['is_living' => false, 'status' => 'active'], ['status' => 'historical', 'verification_status' => 'pending_review']] as $attributes) {
        $right->update($attributes);
        $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right))->assertSessionHasErrors('right_person_id');
    }
    $right->update(['verification_status' => 'verified']);
    $this->post(route('cross-links.store'), sharedProposal($left, $right));
    $link = CrossFamilyLink::query()->sole();
    $left->update(['is_living' => true, 'full_name_ar' => 'اسم سري لا ينشر']);
    $this->actingAs($other)->get(route('cross-links.show', $link))->assertDontSee('اسم سري لا ينشر');
    $this->actingAs($super)->post(route('cross-links.review', $link), ['decision' => 'approved', 'evidence_confirmed' => 1, 'reason' => 'لا يجوز اعتماد سجل شخص حي'])->assertSessionHasErrors('decision');
    $this->get(route('shared-ancestry.index'))->assertDontSee('اسم سري لا ينشر');
});

test('approved links are invalidated by ancestry source and privacy changes including bulk updates', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right));
    $link = CrossFamilyLink::query()->sole();
    approveShared($link, $super);
    $left->update(['title' => 'تعديل معتمد لاحق']);
    expect($link->fresh()->status)->toBe('needs_review');
    $this->get(route('shared-ancestry.index'))->assertDontSee('مطابقة تجريبية صريحة');
    approveShared($link, $super);
    $link->citations()->first()->source->update(['notes' => 'تغير الدليل']);
    expect($link->fresh()->status)->toBe('needs_review');
    approveShared($link, $super);
    Person::query()->whereKey($right->id)->update(['is_living' => true]);
    $this->get(route('shared-ancestry.index'))->assertDontSee('مطابقة تجريبية صريحة');
    expect($link->fresh()->status)->toBe('needs_review');
});

test('review paths extend past one hundred generations and public paths stop at privacy gaps', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $previous = $left;
    for ($i = 1; $i <= 125; $i++) {
        $child = $left->familyTree->people()->create(['first_name_ar' => 'ابن', 'full_name_ar' => 'ابن '.$i, 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
        $child->outgoingRelationships()->create(['family_tree_id' => $left->family_tree_id, 'related_person_id' => $previous->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
        $previous = $child;
    }
    $service = app(CrossFamilyLinkService::class);
    expect($service->path($previous, true))->toHaveCount(126)->and($service->descendants($left))->toHaveCount(126);
    $left->incomingRelationships()->first()->person->update(['is_living' => true]);
    expect($service->descendants($left))->toHaveCount(1)->and($service->path($previous, true))->toHaveCount(124);
});

test('parentage can be proposed in either direction without changing either tree', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right, ['kind' => 'child_parent']))->assertSessionHasNoErrors();
    $link = CrossFamilyLink::query()->sole();
    expect($link->kind)->toBe('parent_child')->and($link->left_person_id)->toBe($right->id)->and($link->right_person_id)->toBe($left->id);
    approveShared($link, $super);
    $this->get(route('shared-ancestry.index'))->assertOk()->assertSee('رابط أبوة مراجع');
    expect(PersonRelationship::query()->count())->toBe(0);
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right, ['kind' => 'parent_child']))->assertSessionHasNoErrors();
    $reverse = CrossFamilyLink::query()->latest('id')->first();
    $this->actingAs($super)->post(route('cross-links.review', $reverse), ['decision' => 'approved', 'evidence_confirmed' => 1, 'reason' => 'اختبار منع دورة بين عائلتين'])->assertSessionHasErrors('decision');
    expect($reverse->fresh()->status)->toBe('pending_review');
});

test('failed ancestry transactions roll back invalidation and successful changes withdraw the match', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right));
    $link = CrossFamilyLink::query()->sole();
    approveShared($link, $super);
    try {
        DB::transaction(function () use ($left) {
            $left->update(['full_name_ar' => 'تعديل لم يكتمل']);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('rollback');
    }
    expect($link->fresh()->status)->toBe('approved');
    $child = $left->familyTree->people()->create(['first_name_ar' => 'ابن', 'full_name_ar' => 'ابن جديد', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    expect($link->fresh()->status)->toBe('needs_review');
    approveShared($link, $super);
    $child->outgoingRelationships()->create(['family_tree_id' => $left->family_tree_id, 'related_person_id' => $left->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    expect($link->fresh()->status)->toBe('needs_review');
});

test('pending match evidence cannot be read through ordinary family source pages by editors or readers', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right));
    $source = CrossFamilyLink::query()->sole()->citations()->first()->source;
    foreach (['editor', 'reader'] as $role) {
        $user = User::factory()->create();
        $left->familyTree->family->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        $this->actingAs($user)->get(route('management.sources.index', 'one'))->assertOk()->assertDontSee('مرجع اختباري');
        $this->get(route('management.sources.show', ['one', $source->id]))->assertForbidden();
    }
});

test('proposal audit snapshots are hidden from editors and readers', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right));
    expect(AuditLog::query()->where('auditable_type', CrossFamilyLink::class)->sole()->reviewers_only)->toBeTrue();
    $reader = User::factory()->create();
    $left->familyTree->family->memberships()->create(['user_id' => $reader->id, 'role' => 'reader']);
    $this->actingAs($reader)->get(route('management.sections.index', ['one', 'audit']))->assertOk()->assertDontSee('مطابقة تجريبية صريحة');
});

test('evidence can be resubmitted only by its owning family admin and requires fresh approval', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right));
    $link = CrossFamilyLink::query()->sole();
    $citation = $link->citations()->sole();
    approveShared($link, $super);
    $data = ['title' => 'مصدر محدث', 'url' => 'https://example.org/revised', 'locator' => 'صفحة ٢', 'notes' => 'دليل محدث يحتاج مراجعة جديدة'];
    $this->actingAs($other)->put(route('cross-links.evidence', [$link, $citation->id]), $data)->assertForbidden();
    $this->actingAs($admin)->put(route('cross-links.evidence', [$link, $citation->id]), $data)->assertSessionHasNoErrors();
    expect($link->fresh()->status)->toBe('pending_review')->and($citation->fresh()->verification_status)->toBe('pending_review');
    $this->get(route('shared-ancestry.index'))->assertDontSee('مصدر محدث');
    approveShared($link, $super);
    $this->get(route('shared-ancestry.index'))->assertSee('مصدر محدث');
});

test('changes to ancestry source provenance withdraw dependent approved links', function () {
    [$super, $admin, $other, $left, $right] = sharedFixture();
    $source = $left->familyTree->family->sources()->create(['source_type' => 'document', 'title' => 'مصدر المسار', 'reference' => 'PATH-001', 'verification_status' => 'sourced']);
    $left->sources()->attach($source);
    $source->citations()->create(['family_tree_id' => $left->family_tree_id, 'person_id' => $left->id, 'field_key' => 'person', 'locator' => 'صفحة ١', 'verification_status' => 'sourced']);
    $this->actingAs($admin)->post(route('cross-links.store'), sharedProposal($left, $right));
    $link = CrossFamilyLink::query()->sole();
    approveShared($link, $super);
    $source->update(['verification_status' => 'pending_review']);
    expect($link->fresh()->status)->toBe('needs_review');
    $this->get(route('shared-ancestry.index'))->assertDontSee('مطابقة تجريبية صريحة');
});
