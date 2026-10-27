<?php

use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Role;
use App\Models\User;
use App\Services\ChangeReviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->family = Family::query()->create(['name_ar' => 'عائلة النسب', 'slug' => 'nasab', 'is_active' => true]);
    $this->tree = $this->family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $published = ['status' => 'historical', 'verification_status' => 'verified', 'is_living' => false];
    $this->root = $this->tree->people()->create(['first_name_ar' => 'زيد', 'full_name_ar' => 'زيد', ...$published]);
    $this->father = $this->tree->people()->create(['first_name_ar' => 'عمر', 'full_name_ar' => 'عمر', ...$published]);
    $this->son = $this->tree->people()->create(['first_name_ar' => 'سالم', 'full_name_ar' => 'سالم', ...$published]);
    $this->tree->update(['root_person_id' => $this->root->id]);
    $edge = fn (Person $child, Person $father) => PersonRelationship::query()->create(['family_tree_id' => $this->tree->id, 'person_id' => $child->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $edge($this->father, $this->root);
    $edge($this->son, $this->father);
    Branch::query()->create(['family_tree_id' => $this->tree->id, 'name_ar' => 'فرع عمر', 'slug' => 'omar', 'root_person_id' => $this->father->id, 'is_active' => true, 'verification_status' => 'verified']);
    $this->as = function (string $role, array $functional = [], ?Family $family = null): User {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $membership = FamilyMembership::query()->create(['family_id' => ($family ?? $this->family)->id, 'user_id' => $user->id, 'role' => $role]);
        foreach ($functional as $key) {
            $membership->functionalRoles()->attach(Role::query()->where('key', $key)->value('id'));
        }

        return $user->fresh();
    };
});

test('quick add from a tree node: name only, father preselected and validated, pending and private', function () {
    $editor = ($this->as)('editor');
    $response = $this->actingAs($editor)->postJson(route('management.people.quick', $this->family->slug), ['father_id' => $this->son->id, 'full_name_ar' => 'محمد بن سالم'])
        ->assertCreated()->assertJsonPath('person.name', 'محمد بن سالم');
    $person = Person::query()->findOrFail($response->json('person.id'));
    $edge = $person->outgoingRelationships()->sole();
    expect($person->first_name_ar)->toBe('محمد')->and($person->verification_status)->toBe('pending_review')
        ->and($person->is_living)->toBeNull()
        ->and($edge->related_person_id)->toBe($this->son->id)->and($edge->verification_status)->toBe('pending_review')->and($edge->is_primary)->toBeTrue()
        ->and(Person::query()->publiclyVisible()->whereKey($person->id)->exists())->toBeFalse();

    // Living status is explicit and never coerced.
    $living = $this->actingAs($editor)->postJson(route('management.people.quick', $this->family->slug), ['father_id' => $this->son->id, 'full_name_ar' => 'علي بن سالم', 'living_status' => 'living'])->assertCreated();
    expect(Person::query()->find($living->json('person.id'))->is_living)->toBeTrue();

    // The father must belong to this tree; a name is required.
    $otherFamily = Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other', 'is_active' => true]);
    $stranger = $otherFamily->familyTrees()->create(['name_ar' => 'ش', 'slug' => 'main', 'is_primary' => true])->people()->create(['first_name_ar' => 'غريب', 'full_name_ar' => 'غريب', 'status' => 'historical', 'verification_status' => 'pending_review']);
    $this->actingAs($editor)->postJson(route('management.people.quick', $this->family->slug), ['father_id' => $stranger->id, 'full_name_ar' => 'ابن'])->assertUnprocessable()->assertJsonValidationErrors('father_id');
    $this->actingAs($editor)->postJson(route('management.people.quick', $this->family->slug), ['father_id' => $this->son->id, 'full_name_ar' => ''])->assertUnprocessable()->assertJsonValidationErrors('full_name_ar');
    expect($stranger->fresh()->incomingRelationships()->count())->toBe(0);
});

test('quick add is server-side authorized: readers, news editors and other families are refused', function () {
    foreach ([($this->as)('reader'), ($this->as)('reader', ['news-editor']), ($this->as)('reader', ['genealogy-reviewer'])] as $user) {
        $this->actingAs($user)->postJson(route('management.people.quick', $this->family->slug), ['father_id' => $this->son->id, 'full_name_ar' => 'ممنوع'])->assertForbidden();
    }
    $foreignEditor = ($this->as)('editor', [], Family::query()->create(['name_ar' => 'ب', 'slug' => 'b', 'is_active' => true]));
    $this->actingAs($foreignEditor)->postJson(route('management.people.quick', $this->family->slug), ['father_id' => $this->son->id, 'full_name_ar' => 'ممنوع'])->assertForbidden();
    // A reader holding the genealogy editor role may add.
    $this->actingAs(($this->as)('reader', ['genealogy-editor']))->postJson(route('management.people.quick', $this->family->slug), ['father_id' => $this->son->id, 'full_name_ar' => 'مسموح'])->assertCreated();
    expect(Person::query()->where('full_name_ar', 'ممنوع')->exists())->toBeFalse();
});

test('duplicate warning before save: candidates with lineage, explicit confirmation, existing person selectable', function () {
    $editor = ($this->as)('editor');
    $existing = $this->tree->people()->create(['first_name_ar' => 'أحمد', 'full_name_ar' => 'أحمد بن سالم', 'status' => 'historical', 'verification_status' => 'pending_review']);
    PersonRelationship::query()->create(['family_tree_id' => $this->tree->id, 'person_id' => $existing->id, 'related_person_id' => $this->son->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'pending_review']);

    // Different hamza spelling, same father: blocked until confirmed; the existing record is offered.
    $refused = $this->actingAs($editor)->postJson(route('management.people.quick', $this->family->slug), ['father_id' => $this->son->id, 'full_name_ar' => 'احمد بن سالم'])
        ->assertUnprocessable()->assertJsonValidationErrors('confirm_distinct');
    expect($refused->json('candidates.0.id'))->toBe($existing->id)
        ->and($refused->json('candidates.0.blocking'))->toBeTrue()
        ->and($refused->json('candidates.0.lineage'))->toBe('بن سالم بن عمر بن زيد')
        ->and($refused->json('candidates.0.url'))->toBe(route('management.people.show', [$this->family->slug, $existing->id, 'tree' => 'main']))
        ->and(Person::query()->where('name_search', 'احمد بن سالم')->count())->toBe(1);

    $candidates = $this->actingAs($editor)->getJson(route('management.people.candidates', [$this->family->slug, 'full_name_ar' => 'أحمد', 'father_id' => $this->son->id]))->assertOk();
    expect(collect($candidates->json('data'))->firstWhere('id', $existing->id)['reason'])->toBe('أخ بالاسم الأول نفسه أو اسم مشابه')
        ->and($candidates->json('father.name'))->toBe('سالم')
        ->and($candidates->json('father.branches'))->toBe(['فرع عمر'])
        ->and($candidates->json('father.children'))->toBe(['أحمد بن سالم']);
    $elsewhere = $this->actingAs($editor)->getJson(route('management.people.candidates', [$this->family->slug, 'full_name_ar' => 'أحمد بن سالم', 'father_id' => $this->root->id]))->assertOk();
    expect($elsewhere->json('data.0.reason'))->toBe('الاسم نفسه في موضع آخر من الشجرة')->and($elsewhere->json('data.0.blocking'))->toBeFalse();

    $this->actingAs($editor)->postJson(route('management.people.quick', $this->family->slug), ['father_id' => $this->son->id, 'full_name_ar' => 'احمد بن سالم', 'confirm_distinct' => true])->assertCreated();
    expect(Person::query()->where('name_search', 'احمد بن سالم')->count())->toBe(2);
    // Candidates are read-only and need a genealogy permission in this family.
    $this->actingAs(User::factory()->create(['is_active' => true]))->getJson(route('management.people.candidates', [$this->family->slug, 'full_name_ar' => 'أحمد']))->assertForbidden();
});

test('guided creation page preselects the father with lineage and derived branch, and supports adding brothers in sequence', function () {
    $editor = ($this->as)('editor');
    $this->actingAs($editor)->get(route('management.records.create', [$this->family->slug, 'people', 'father_id' => $this->son->id]))->assertOk()
        ->assertSee('سيُضاف ابنًا لـ')->assertSee('بن عمر بن زيد')->assertSee('فرع عمر')
        ->assertSee('التحقق من التكرار')->assertSee('المراجعة والحفظ')
        ->assertSee('<option value="'.$this->son->id.'" selected>', false);

    $this->actingAs($editor)->post(route('management.people.store', [$this->family->slug, 'tree' => 'main']), [
        'first_name_ar' => 'خالد', 'full_name_ar' => 'خالد بن سالم', 'alternate_names' => ['خالد السالم', ''], 'status' => 'historical', 'verification_status' => 'pending_review',
        'father_id' => $this->son->id, 'father_verification_status' => 'pending_review', 'add_another' => 1, 'branch_enabled' => 0,
    ])->assertSessionHasNoErrors()->assertRedirect(route('management.records.create', [$this->family->slug, 'people', 'tree' => 'main', 'father_id' => $this->son->id]));
    $created = Person::query()->where('full_name_ar', 'خالد بن سالم')->sole();
    expect($created->alternate_names)->toBe(['خالد السالم'])
        ->and($created->outgoingRelationships()->sole()->related_person_id)->toBe($this->son->id);
    // A father outside the tree is refused by the server, whatever the form showed.
    $this->actingAs($editor)->get(route('management.records.create', [$this->family->slug, 'people', 'father_id' => 999999]))->assertNotFound();
});

test('editing: published records are read-only for editors with a correction path; publishers see the impact of a father change', function () {
    $editor = ($this->as)('editor');
    $this->actingAs($editor)->get(route('management.records.edit', [$this->family->slug, 'people', $this->father->id]))->assertOk()
        ->assertSee('سجل منشور للعامة')->assertSee('اقتراح تصحيح')->assertSee('<fieldset disabled>', false)
        ->assertSee(route('management.changes.create', [$this->family->slug, 'tree' => 'main', 'kind' => 'person', 'target' => $this->father->id]));
    $admin = ($this->as)('admin');
    $this->actingAs($admin)->get(route('management.records.edit', [$this->family->slug, 'people', $this->father->id]))->assertOk()
        ->assertDontSee('<fieldset disabled>', false)->assertSee('النسب والعلاقات')->assertSee('السجل والتدقيق')->assertSee('data-descendants="1"', false)
        ->assertSee('يؤسس: فرع عمر');
    // The server still enforces the rule if a read-only form is forced.
    $this->actingAs($editor)->put(route('management.people.update', [$this->family->slug, $this->father->id, 'tree' => 'main']), [
        'first_name_ar' => 'عمر', 'full_name_ar' => 'عمر المعدل', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => 0, 'father_id' => $this->root->id,
    ])->assertSessionHasErrors();
    expect($this->father->fresh()->full_name_ar)->toBe('عمر');
    // Functional roles: a genealogy reviewer cannot open the edit form; a genealogy editor can.
    $this->actingAs(($this->as)('reader', ['genealogy-reviewer']))->get(route('management.records.edit', [$this->family->slug, 'people', $this->son->id]))->assertForbidden();
    $this->actingAs(($this->as)('reader', ['genealogy-editor']))->get(route('management.records.edit', [$this->family->slug, 'people', $this->son->id]))->assertOk();
});

test('restricted fields stay masked on the edit form', function () {
    $pending = $this->tree->people()->create(['first_name_ar' => 'نادر', 'full_name_ar' => 'نادر', 'status' => 'active', 'verification_status' => 'pending_review', 'is_living' => true, 'birth_year' => 1987, 'field_visibility' => ['birth_year' => 'restricted']]);
    $this->actingAs(($this->as)('editor'))->get(route('management.records.edit', [$this->family->slug, 'people', $pending->id]))->assertOk()
        ->assertDontSee('1987')->assertSee('محجوب بحسب إعداد الخصوصية');
    $this->actingAs(($this->as)('admin'))->get(route('management.records.edit', [$this->family->slug, 'people', $pending->id]))->assertOk()->assertSee('1987');
});

test('review page: values, impact, publication effect, earlier decisions, own-request notice and required reason', function () {
    $submitter = ($this->as)('reader');
    $reviewer = ($this->as)('reader', ['genealogy-reviewer']);
    $reviews = app(ChangeReviewService::class);
    $first = $reviews->submit($this->tree, $submitter, 'person', 'update', $this->father->id, ['full_name_ar' => 'عمر الأول', 'first_name_ar' => 'عمر'], 'تهجئة من مخطوط');
    $reviews->decide($this->tree, $first, $reviewer, 'rejected', 'المخطوط متأخر');
    $change = $reviews->submit($this->tree, $submitter, 'person', 'update', $this->father->id, ['full_name_ar' => 'عمر بن زيد', 'first_name_ar' => 'عمر'], 'توحيد الاسم');

    $this->actingAs($reviewer)->get(route('management.changes.show', [$this->family->slug, $change->id]))->assertOk()
        ->assertSee($submitter->name)->assertSee('عمر بن زيد')->assertSee('الأثر على النسب')->assertSee('ومعه 1 من الذرية المسجلة')
        ->assertSee('الآن: ظاهر للعامة')->assertSee('بعد القبول: سيظهر للعامة')
        ->assertSee('المخطوط متأخر')->assertSee('موضعه في الشجرة والأسلاف')->assertSee('قبول وتطبيق');
    $this->actingAs($submitter)->get(route('management.changes.show', [$this->family->slug, $change->id]))->assertOk()
        ->assertSee('قدّمت هذا المقترح بنفسك')->assertDontSee('قبول وتطبيق');
    $this->actingAs($reviewer)->post(route('management.changes.decide', [$this->family->slug, $change->id]), ['decision' => 'rejected', 'decision_reason' => ''])->assertSessionHasErrors('decision_reason');
    expect($change->fresh()->status)->toBe('pending');
    $this->actingAs($reviewer)->post(route('management.changes.decide', [$this->family->slug, $change->id]), ['decision' => 'accepted', 'decision_reason' => 'مطابق'])->assertSessionHasNoErrors();
    expect($this->father->fresh()->full_name_ar)->toBe('عمر بن زيد')->and(ChangeRequest::query()->where('status', 'accepted')->count())->toBe(1);
});

test('tree node actions follow permissions: add for creators, propose for members, nothing for visitors', function () {
    // The full-tree payload carries action URL templates only for permitted actions; the client fills in the node id.
    $actionsFor = function (?User $user) {
        $user ? $this->actingAs($user) : auth()->logout();
        $payload = $this->getJson(route('genealogy.graph', [$this->family->slug, 'all' => 1]))->assertOk()->json();
        $id = (string) $payload['meta']['id_placeholder'];

        return collect($payload['meta']['templates'])->map(fn (string $template) => str_replace($id, (string) $this->son->id, $template))->all();
    };
    $editor = $actionsFor(($this->as)('editor'));
    expect($editor['add_child'])->toContain('father_id='.$this->son->id)->and($editor['edit'])->not->toBeNull()->and($editor['propose'])->toContain('target='.$this->son->id)->and($editor['person'])->toContain('/people/'.$this->son->id);
    $reader = $actionsFor(($this->as)('reader'));
    expect($reader)->not->toHaveKey('add_child')->not->toHaveKey('edit')->toHaveKey('propose');
    $newsEditor = $actionsFor(($this->as)('reader', ['news-editor']));
    expect($newsEditor)->not->toHaveKey('add_child');
    $visitor = $actionsFor(null);
    expect(array_keys($visitor))->toBe(['person']);

    $this->actingAs(($this->as)('editor'))->get(route('genealogy.tree', $this->family->slug))->assertOk()->assertSee('data-quick-add-url', false)->assertSee('إضافة ابن من هنا');
    $this->actingAs(($this->as)('reader'))->get(route('genealogy.tree', $this->family->slug))->assertOk()->assertDontSee('data-quick-add-url', false);
});
