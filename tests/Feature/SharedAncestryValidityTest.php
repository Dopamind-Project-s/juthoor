<?php

use App\Models\AuditLog;
use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\AuditReversionService;
use App\Services\ChangeReviewService;
use App\Services\Genealogy\CrossFamilyLinkService;
use App\Services\Genealogy\GenealogyAssertionService;
use App\Services\Genealogy\PersonMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Two families with an approved same-person link between their roots; the left root has two sons and a grandson.
 *
 * @return array<string, mixed>
 */
function validityFixture(): array
{
    $super = User::factory()->create();
    $super->forceFill(['is_super_admin' => true, 'is_active' => true])->save();
    $admin = User::factory()->create();
    $other = User::factory()->create();
    [$left, $right] = collect(['one' => $admin, 'two' => $other])->map(function (User $owner, string $slug) {
        $family = Family::query()->create(['slug' => $slug, 'name_ar' => 'عائلة '.$slug, 'is_active' => true]);
        $tree = $family->familyTrees()->create(['slug' => 'main', 'name_ar' => 'الرئيسية', 'is_primary' => true, 'is_active' => true]);
        $root = $tree->people()->create(['first_name_ar' => 'آدم', 'full_name_ar' => 'آدم متشابه', 'status' => 'historical', 'is_living' => false, 'verification_status' => 'verified']);
        $tree->update(['root_person_id' => $root->id]);
        $family->memberships()->create(['user_id' => $owner->id, 'role' => 'admin']);

        return $root;
    })->values()->all();
    $tree = $left->familyTree;
    $child = fn (string $name, Person $father, array $extra = []) => tap($tree->people()->create($extra + ['first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'is_living' => false, 'verification_status' => 'verified']),
        fn (Person $person) => $tree->personRelationships()->create(['person_id' => $person->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']));
    $son = $child('سالم', $left);
    $brother = $child('سليم', $left);
    $grandson = $child('حفيد معروض', $son);
    $living = $child('حفيد حي سري', $son, ['status' => 'living', 'is_living' => true]);
    test()->actingAs($admin)->post(route('cross-links.store'), [
        'family' => 'one', 'left_person_id' => $left->id, 'right_person_id' => $right->id, 'kind' => 'same_person',
        'claim' => 'مطابقة تجريبية صريحة', 'source_title' => 'مرجع اختباري', 'source_url' => 'https://example.org/synthetic-record',
        'reference' => 'SYNTHETIC-002', 'locator' => 'صفحة ١', 'evidence' => 'دليل اصطناعي لاختبار الصلاحية فقط.',
    ])->assertSessionHasNoErrors();
    $link = CrossFamilyLink::query()->sole();
    approveValidity($link, $super);
    test()->travel(2)->seconds();

    return compact('super', 'admin', 'other', 'left', 'right', 'son', 'brother', 'grandson', 'living', 'link');
}

function approveValidity(CrossFamilyLink $link, User $super): void
{
    app(CrossFamilyLinkService::class)->review($link, $super, ['decision' => 'approved', 'reason' => 'دليل اصطناعي كاف لاختبار المراجعة فقط', 'evidence_confirmed' => true]);
}

/** Number of whole-tree fingerprints (full validations) run while executing the callback. */
function fullValidations(callable $callback): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count) {
        if (str_contains($query->sql, 'from "person_relationships" where "family_tree_id" in')) {
            $count++;
        }
    });
    $callback();
    DB::flushQueryLog();

    return $count;
}

test('approval records the validation and later public views trust it without revalidating', function () {
    $fixture = validityFixture();
    $link = $fixture['link']->fresh();
    expect($link->status)->toBe('approved')->and($link->validated_at)->not->toBeNull()->and($link->dependency_hash)->not->toBeNull();

    // The approval itself wrote dependencies in its own second, so the first view revalidates once, then never again.
    $first = fullValidations(fn () => $this->get(route('shared-ancestry.index'))->assertOk()->assertSee('مطابقة تجريبية صريحة'));
    $this->travel(2)->seconds();
    $validatedAt = CrossFamilyLink::query()->value('validated_at');
    $steady = fullValidations(function () {
        $this->get(route('shared-ancestry.index'))->assertOk()->assertSee('مطابقة تجريبية صريحة')->assertSee('حفيد معروض');
        $this->get(route('shared-ancestry.index'))->assertOk()->assertSee('مطابقة تجريبية صريحة');
    });

    expect($first)->toBeLessThanOrEqual(1)->and($steady)->toBe(0)
        ->and(CrossFamilyLink::query()->value('validated_at'))->toEqual($validatedAt)
        ->and(app(CrossFamilyLinkService::class)->validity($link->fresh()))->toBe('valid');
});

test('the public page never lists living or unreviewed descendants of a trusted link', function () {
    $fixture = validityFixture();
    $this->get(route('shared-ancestry.index'));
    $this->travel(2)->seconds();

    $this->get(route('shared-ancestry.index'))->assertSee('حفيد معروض')->assertDontSee('حفيد حي سري');
    $this->actingAs($fixture['admin'])->get(route('shared-ancestry.index'))->assertDontSee('حفيد حي سري');
});

test('parent changes, approved corrections and deletions invalidate the link when they are written', function (string $change) {
    $fixture = validityFixture();
    match ($change) {
        'reparent' => PersonRelationship::query()->where('person_id', $fixture['grandson']->id)->sole()->update(['related_person_id' => $fixture['brother']->id]),
        'rename' => $fixture['grandson']->update(['full_name_ar' => 'اسم مصحح']),
        'delete' => $fixture['grandson']->delete(),
        'tree' => $fixture['left']->familyTree->update(['is_active' => false]),
    };

    expect($fixture['link']->fresh()->status)->toBe('needs_review')
        ->and($fixture['link']->fresh()->validation_stamp)->toBeNull();
    $this->get(route('shared-ancestry.index'))->assertDontSee('مطابقة تجريبية صريحة');
    expect(AuditLog::query()->where('auditable_type', (new CrossFamilyLink)->getMorphClass())->where('action', 'invalidated')->count())->toBe(1);
})->with(['reparent', 'rename', 'delete', 'tree']);

test('an accepted fact change made through review invalidates the link', function () {
    $fixture = validityFixture();
    $assertions = app(GenealogyAssertionService::class);
    $claim = $assertions->record($fixture['left']->familyTree, $fixture['admin'], $fixture['grandson'], ['fact_type' => 'title', 'value' => 'لقب معتمد']);
    $change = $assertions->propose($claim, $fixture['admin'], 'لقب');
    app(ChangeReviewService::class)->decide($fixture['left']->familyTree, $change, $fixture['super'], 'accepted', 'مراجعة');

    expect($fixture['grandson']->fresh()->title)->toBe('لقب معتمد')->and($fixture['link']->fresh()->status)->toBe('needs_review');
});

test('recording, citing and classifying unapproved claims leaves link validity untouched', function () {
    $fixture = validityFixture();
    $this->get(route('shared-ancestry.index'));
    $this->travel(2)->seconds();
    $before = $fixture['link']->fresh()->only(['status', 'validated_at', 'validation_stamp', 'dependency_hash']);
    $tree = $fixture['left']->familyTree;
    $source = $tree->family->sources()->create(['source_type' => 'book', 'title' => 'مرجع رواية', 'verification_status' => 'pending_review']);
    $this->travel(2)->seconds();
    $this->get(route('shared-ancestry.index'));
    $this->travel(2)->seconds();
    $before = $fixture['link']->fresh()->only(['status', 'validated_at', 'validation_stamp', 'dependency_hash']);

    $assertions = app(GenealogyAssertionService::class);
    $validations = fullValidations(function () use ($assertions, $tree, $fixture, $source) {
        $claim = $assertions->record($tree, $fixture['admin'], $fixture['son'], ['fact_type' => 'full_name', 'value' => 'سالم بن آدم']);
        $assertions->cite($claim, $fixture['admin'], $source, ['locator' => 'ص ٤', 'verification_status' => 'sourced']);
        $assertions->classify($claim, $fixture['admin'], ['relation' => 'alternate_spelling', 'verification_status' => 'unresolved']);
        $assertions->propose($claim->fresh(), $fixture['admin'], 'مقترح لم يُراجع');
        $this->get(route('shared-ancestry.index'))->assertSee('مطابقة تجريبية صريحة');
    });

    expect($validations)->toBe(0)
        ->and($fixture['link']->fresh()->only(['status', 'validated_at', 'validation_stamp', 'dependency_hash']))->toEqual($before);
});

test('a write that bypasses model events marks the link stale and it is never shown unverified', function () {
    $fixture = validityFixture();
    $this->get(route('shared-ancestry.index'));
    $this->travel(2)->seconds();
    Person::query()->whereKey($fixture['grandson']->id)->update(['full_name_ar' => 'تعديل جماعي']);
    $this->travel(2)->seconds();

    expect($fixture['link']->fresh()->status)->toBe('approved');
    $this->actingAs($fixture['super'])->get(route('cross-links.index'))->assertOk()->assertSee('data-link-validity="stale"', false);
    expect($fixture['link']->fresh()->status)->toBe('approved');

    $this->post(route('cross-links.revalidate', $fixture['link']))->assertSessionHasNoErrors();
    expect($fixture['link']->fresh()->status)->toBe('needs_review');
    $this->get(route('cross-links.index'))->assertSee('data-link-validity="needs_review"', false);
    $this->get(route('shared-ancestry.index'))->assertDontSee('مطابقة تجريبية صريحة');
});

test('a stale link whose revalidation is busy elsewhere is hidden, then revalidated after the response', function () {
    $fixture = validityFixture();
    $this->get(route('shared-ancestry.index'));
    $this->travel(2)->seconds();
    CrossFamilyLink::query()->whereKey($fixture['link']->id)->update(['validation_stamp' => str_repeat('0', 64)]);

    $lock = Cache::lock('cross-family-link-validation:'.$fixture['link']->id, 30);
    expect($lock->get())->toBeTrue();
    $this->get(route('shared-ancestry.index'))->assertOk()->assertDontSee('مطابقة تجريبية صريحة');
    $lock->release();

    $link = $fixture['link']->fresh();
    expect($link->status)->toBe('approved')->and($link->validation_stamp)->not->toBe(str_repeat('0', 64));
    $this->travel(2)->seconds();
    $this->get(route('shared-ancestry.index'))->assertSee('مطابقة تجريبية صريحة');
});

test('a stamp is never trusted while its dependencies were written in the same second', function () {
    $fixture = validityFixture();
    $service = app(CrossFamilyLinkService::class);
    $fixture['brother']->updateQuietly(['notes' => 'x']);
    Person::query()->whereKey($fixture['brother']->id)->update(['notes' => 'كتابة في الثانية نفسها']);

    expect($service->stamp($fixture['link']->fresh()))->toBeNull();
    $this->travel(1)->seconds();
    expect($service->stamp($fixture['link']->fresh()))->toBeString();
});

test('only reviewers of an involved family may trigger revalidation, and only for approved links', function () {
    $fixture = validityFixture();
    $editor = User::factory()->create();
    $fixture['left']->familyTree->family->memberships()->create(['user_id' => $editor->id, 'role' => 'editor']);

    $this->actingAs($editor)->post(route('cross-links.revalidate', $fixture['link']))->assertForbidden();
    $this->actingAs($fixture['admin'])->post(route('cross-links.revalidate', $fixture['link']))->assertSessionHasNoErrors();
    CrossFamilyLink::query()->whereKey($fixture['link']->id)->update(['status' => 'pending_review']);
    $this->post(route('cross-links.revalidate', $fixture['link']))->assertSessionHasErrors('link');
    expect($fixture['link']->fresh()->status)->toBe('pending_review');
});

test('merge and merge undo invalidate the link', function () {
    $fixture = validityFixture();
    $tree = $fixture['left']->familyTree;
    $duplicate = $tree->people()->create(['first_name_ar' => 'سليم', 'full_name_ar' => 'سليم', 'status' => 'historical', 'is_living' => false, 'verification_status' => 'verified']);
    $tree->personRelationships()->create(['person_id' => $duplicate->id, 'related_person_id' => $fixture['left']->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    approveValidity($fixture['link']->fresh(), $fixture['super']);
    $merges = app(PersonMergeService::class);

    $plan = $merges->plan($tree, $fixture['brother']->id, $duplicate->id);
    $operation = $merges->merge($tree, $fixture['super'], $fixture['brother']->id, $duplicate->id, [], $plan['fingerprint'], 'تكرار');
    expect($fixture['link']->fresh()->status)->toBe('needs_review');

    approveValidity($fixture['link']->fresh(), $fixture['super']);
    $merges->undo(PersonMerge::query()->findOrFail($operation->id), $fixture['super'], 'تراجع');
    expect($fixture['link']->fresh()->status)->toBe('needs_review');
});

test('restoring a deleted person and reverting an edit invalidate the link', function () {
    $fixture = validityFixture();
    $loose = $fixture['left']->familyTree->people()->create(['first_name_ar' => 'سجل', 'full_name_ar' => 'سجل منفرد', 'status' => 'historical', 'is_living' => false, 'verification_status' => 'pending_review']);
    approveValidity($fixture['link']->fresh(), $fixture['super']);
    $this->actingAs($fixture['admin'])->delete(route('management.people.destroy', ['one', $loose->id]))->assertSessionHasNoErrors();
    expect($fixture['link']->fresh()->status)->toBe('needs_review');
    approveValidity($fixture['link']->fresh(), $fixture['super']);
    $recovery = app(AuditReversionService::class);

    $deletion = AuditLog::query()->where('auditable_type', (new Person)->getMorphClass())->where('auditable_id', $loose->id)->where('action', 'deleted')->sole();
    $recovery->revert($deletion, $fixture['super'], 'حذف بالخطأ');
    expect(Person::query()->whereKey($loose->id)->exists())->toBeTrue()
        ->and($fixture['link']->fresh()->status)->toBe('needs_review');

    approveValidity($fixture['link']->fresh(), $fixture['super']);
    $this->actingAs($fixture['admin'])->put(route('management.people.update', ['one', $loose->id]), ['first_name_ar' => 'سجل', 'full_name_ar' => 'سجل منفرد معدل', 'status' => 'historical', 'verification_status' => 'pending_review', 'is_living' => '0'])->assertSessionHasNoErrors();
    approveValidity($fixture['link']->fresh(), $fixture['super']);
    $edit = AuditLog::query()->where('auditable_type', (new Person)->getMorphClass())->where('auditable_id', $loose->id)->where('action', 'updated')->latest('id')->firstOrFail();
    $recovery->revert($edit, $fixture['super'], 'تراجع عن تعديل');
    expect($loose->fresh()->full_name_ar)->toBe('سجل منفرد')->and($fixture['link']->fresh()->status)->toBe('needs_review');
});

test('one write re-checks every approved link of the family, not only the first invalid one', function () {
    $fixture = validityFixture();
    $rightTree = $fixture['right']->familyTree;
    $rightChild = $rightTree->people()->create(['first_name_ar' => 'ابن', 'full_name_ar' => 'ابن الطرف الآخر', 'status' => 'historical', 'is_living' => false, 'verification_status' => 'verified']);
    $rightTree->personRelationships()->create(['person_id' => $rightChild->id, 'related_person_id' => $fixture['right']->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $this->actingAs($fixture['admin'])->post(route('cross-links.store'), [
        'family' => 'one', 'left_person_id' => $fixture['grandson']->id, 'right_person_id' => $rightChild->id, 'kind' => 'same_person',
        'claim' => 'مطابقة ثانية', 'source_title' => 'مرجع ثان', 'source_url' => 'https://example.org/second', 'reference' => 'SYNTHETIC-003', 'locator' => 'صفحة ٢', 'evidence' => 'دليل اصطناعي ثان.',
    ])->assertSessionHasNoErrors();
    $links = CrossFamilyLink::query()->orderBy('id')->get();
    approveValidity($links[0]->fresh(), $fixture['super']);
    approveValidity($links[1]->fresh(), $fixture['super']);
    expect(CrossFamilyLink::query()->where('status', 'approved')->count())->toBe(2);

    $fixture['brother']->update(['notes' => 'تعديل يمس الشجرة']);

    expect(CrossFamilyLink::query()->pluck('status')->all())->toBe(['needs_review', 'needs_review']);
});

test('the shared encoding produces the same fingerprint as encoding every dependency at once', function () {
    $fixture = validityFixture();
    $link = $fixture['link']->fresh();
    $ids = Person::query()->whereKey([$link->left_person_id, $link->right_person_id])->pluck('family_tree_id');
    $original = hash('sha256', json_encode([
        $link->only(['left_person_id', 'right_person_id', 'kind', 'claim']),
        FamilyTree::query()->whereKey($ids)->orderBy('id')->get()->toArray(),
        Person::query()->whereIn('family_tree_id', $ids)->with('sources', 'personAttributes')->orderBy('id')->get()->toArray(),
        PersonRelationship::query()->whereIn('family_tree_id', $ids)->with('source')->orderBy('id')->get()->toArray(),
        Citation::query()->whereIn('family_tree_id', $ids)->whereNull('cross_family_link_id')->whereNull('assertion_id')->with('source')->orderBy('id')->get()->toArray(),
        $link->citations()->with('source')->orderBy('id')->get()->toArray(),
    ], JSON_THROW_ON_ERROR));
    $memo = [];

    expect(app(CrossFamilyLinkService::class)->fingerprint($link, $memo))->toBe($original)->toBe($link->dependency_hash)
        ->and(app(CrossFamilyLinkService::class)->fingerprint($link, $memo))->toBe($original);
});
