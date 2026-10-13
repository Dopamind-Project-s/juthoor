<?php

use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
function reviewContext(): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة مراجعة اصطناعية', 'slug' => 'review-family', 'is_active' => true]);
    $tree = $family->familyTrees()->create(['name_ar' => 'الأولى', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $reader = User::factory()->create();
    $editor = User::factory()->create();
    $admin = User::factory()->create();
    foreach ([[$reader, 'reader'], [$editor, 'editor'], [$admin, 'admin']] as [$user,$role]) {
        $family->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }
    $person = $tree->people()->create(['first_name_ar' => 'جذر', 'full_name_ar' => 'جذر اصطناعي', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);

    return compact('family', 'tree', 'reader', 'editor', 'admin', 'person');
}
function reviewInput(array $extra = []): array
{
    return array_replace(['kind' => 'person', 'operation' => 'create', 'first_name_ar' => 'جديد', 'full_name_ar' => 'شخص مقترح', 'status' => 'historical', 'verification_status' => 'pending_review', 'reason' => 'توثيق اصطناعي'], $extra);
}
test('readers submit isolated proposals and only another administrator applies the decision atomically', function () {
    extract(reviewContext());
    $this->actingAs($reader)->post(route('management.changes.store', [$family->slug, 'tree' => 'main']), reviewInput())->assertRedirect()->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    $this->assertDatabaseCount('people', 1);
    $url = route('management.changes.decide', [$family->slug, $change->id, 'tree' => 'main']);
    foreach ([$reader, $editor] as $user) {
        $this->actingAs($user)->post($url, ['decision' => 'accepted', 'decision_reason' => 'تم التحقق'])->assertForbidden();
    }
    $this->actingAs($admin)->get(route('management.changes.show', [$family->slug, $change->id, 'tree' => 'main']))->assertSee('شخص مقترح')->assertSee('قبول وتطبيق');
    $this->post($url, ['decision' => 'accepted', 'decision_reason' => 'تم التحقق'])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseCount('people', 2);
    expect($change->fresh()->status)->toBe('accepted');
    $this->assertDatabaseHas('audit_logs', ['id' => $change->fresh()->applied_audit_id, 'action' => 'created']);
    $this->post($url, ['decision' => 'accepted', 'decision_reason' => 'مرة ثانية'])->assertSessionHasErrors('change');
    $this->assertDatabaseCount('people', 2);
});
test('later edits and even a later edit restored to the same value block proposal approval', function () {
    extract(reviewContext());
    $this->actingAs($reader)->post(route('management.changes.store', $family->slug), reviewInput(['operation' => 'update', 'target_id' => $person->id, 'full_name_ar' => 'اسم مقترح']))->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    $person->update(['full_name_ar' => 'تعديل أحدث']);
    $this->actingAs($admin)->post(route('management.changes.decide', [$family->slug, $change->id]), ['decision' => 'accepted', 'decision_reason' => 'اعتماد'])->assertSessionHasErrors('change');
    expect($person->fresh()->full_name_ar)->toBe('تعديل أحدث');
    expect($change->fresh()->status)->toBe('pending');
});
test('review rejection needs a reason and never changes approved data while self review is refused', function () {
    extract(reviewContext());
    $this->actingAs($admin)->post(route('management.changes.store', $family->slug), reviewInput())->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    $url = route('management.changes.decide', [$family->slug, $change->id]);
    $this->post($url, ['decision' => 'accepted', 'decision_reason' => 'اعتماد ذاتي'])->assertSessionHasErrors('change');
    $other = User::factory()->create();
    $family->memberships()->create(['user_id' => $other->id, 'role' => 'admin']);
    $this->actingAs($other)->post($url, ['decision' => 'rejected'])->assertSessionHasErrors('decision_reason');
    $this->post($url, ['decision' => 'rejected', 'decision_reason' => 'المصدر غير كافٍ'])->assertSessionHasNoErrors();
    expect($change->fresh()->status)->toBe('rejected');
    $this->assertDatabaseCount('people', 1);
});
test('proposals remain scoped to their family and selected tree', function () {
    extract(reviewContext());
    $second = $family->familyTrees()->create(['name_ar' => 'الثانية', 'slug' => 'second', 'is_active' => true]);
    $foreign = Family::query()->create(['name_ar' => 'عائلة ثانية', 'slug' => 'other']);
    $foreignTree = $foreign->familyTrees()->create(['name_ar' => 'أخرى', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $outsider = User::factory()->create();
    $foreign->memberships()->create(['user_id' => $outsider->id, 'role' => 'admin']);
    $this->actingAs($reader)->post(route('management.changes.store', [$family->slug, 'tree' => 'second']), reviewInput(['operation' => 'update', 'target_id' => $person->id]))->assertNotFound();
    $this->post(route('management.changes.store', [$family->slug, 'tree' => 'second']), reviewInput())->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    expect($change->family_tree_id)->toBe($second->id);
    $this->get(route('management.changes.show', [$family->slug, $change->id, 'tree' => 'main']))->assertNotFound();
    $this->actingAs($outsider)->get(route('management.changes.index', $family->slug))->assertForbidden();
    $this->get(route('management.changes.show', [$foreign->slug, $change->id]))->assertNotFound();
});
test('relationship and branch proposals use ancestry integrity again at approval', function () {
    extract(reviewContext());
    $child = $tree->people()->create(['first_name_ar' => 'ابن', 'full_name_ar' => 'ابن اصطناعي', 'status' => 'historical', 'verification_status' => 'verified']);
    $this->actingAs($reader)->post(route('management.changes.store', $family->slug), ['kind' => 'relationship', 'operation' => 'create', 'person_id' => $child->id, 'father_id' => $person->id, 'verification_status' => 'verified', 'reason' => 'علاقة موثقة'])->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    $tree->personRelationships()->create(['person_id' => $person->id, 'related_person_id' => $child->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $this->actingAs($admin)->post(route('management.changes.decide', [$family->slug, $change->id]), ['decision' => 'accepted', 'decision_reason' => 'قبول'])->assertSessionHasErrors('change');
    expect($change->fresh()->status)->toBe('pending');
    $this->assertDatabaseCount('person_relationships', 1);
    $this->actingAs($reader)->post(route('management.changes.store', $family->slug), ['kind' => 'branch', 'operation' => 'create', 'name_ar' => 'فرع جديد', 'slug' => 'new-branch', 'root_person_id' => $child->id, 'verification_status' => 'verified', 'is_active' => 1, 'reason' => 'فرع اصطناعي'])->assertSessionHasNoErrors();
    $branchChange = ChangeRequest::query()->latest('id')->first();
    $this->actingAs($admin)->post(route('management.changes.decide', [$family->slug, $branchChange->id]), ['decision' => 'accepted', 'decision_reason' => 'صحيح'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('branches', ['family_tree_id' => $tree->id, 'slug' => 'new-branch']);
});

test('two proposals based on the same record cannot overwrite each other after the first approval', function () {
    extract(reviewContext());
    foreach (['التعديل الأول', 'التعديل الثاني'] as $name) {
        $this->actingAs($reader)->post(route('management.changes.store', $family->slug), reviewInput(['operation' => 'update', 'target_id' => $person->id, 'full_name_ar' => $name]))->assertSessionHasNoErrors();
    }
    [$first, $second] = ChangeRequest::query()->orderBy('id')->get()->all();
    $this->actingAs($admin)->post(route('management.changes.decide', [$family->slug, $first->id]), ['decision' => 'accepted', 'decision_reason' => 'الأول صحيح'])->assertSessionHasNoErrors();
    $this->post(route('management.changes.decide', [$family->slug, $second->id]), ['decision' => 'accepted', 'decision_reason' => 'الثاني صحيح'])->assertSessionHasErrors('change');
    expect($person->fresh()->full_name_ar)->toBe('التعديل الأول');
    expect($second->fresh()->status)->toBe('pending');
});

test('a deleted target produces a review conflict instead of silently recreating it', function () {
    extract(reviewContext());
    $this->actingAs($reader)->post(route('management.changes.store', $family->slug), reviewInput(['operation' => 'update', 'target_id' => $person->id]))->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    $person->delete();
    $this->actingAs($admin)->post(route('management.changes.decide', [$family->slug, $change->id]), ['decision' => 'accepted', 'decision_reason' => 'اعتماد'])->assertSessionHasErrors('change');
    $this->assertDatabaseCount('people', 0);
    expect($change->fresh()->status)->toBe('pending');
});

test('approved branch and father edits update the existing scoped record and preserve audit differences', function (string $kind) {
    extract(reviewContext());
    $child = $tree->people()->create(['first_name_ar' => 'ابن', 'full_name_ar' => 'ابن المراجعة', 'status' => 'historical', 'verification_status' => 'verified']);
    $otherFather = $tree->people()->create(['first_name_ar' => 'أب', 'full_name_ar' => 'أب بديل', 'status' => 'historical', 'verification_status' => 'verified']);
    $edge = $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $person->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    if ($kind === 'branch') {
        $record = $tree->branches()->create(['name_ar' => 'اسم الفرع القديم', 'slug' => 'old', 'root_person_id' => $person->id, 'is_active' => true, 'verification_status' => 'verified']);
        $data = ['name_ar' => 'اسم الفرع الجديد', 'slug' => 'new', 'root_person_id' => $child->id, 'is_active' => 1, 'verification_status' => 'verified'];
    } else {
        $record = $edge;
        $data = ['person_id' => $child->id, 'father_id' => $otherFather->id, 'verification_status' => 'verified'];
    }
    $this->actingAs($reader)->post(route('management.changes.store', $family->slug), array_replace($data, ['kind' => $kind, 'operation' => 'update', 'target_id' => $record->id, 'reason' => 'تصحيح اصطناعي']))->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    $this->actingAs($admin)->get(route('management.changes.show', [$family->slug, $change->id]))->assertSee('ابن المراجعة');
    $this->post(route('management.changes.decide', [$family->slug, $change->id]), ['decision' => 'accepted', 'decision_reason' => 'فُحص المصدر'])->assertSessionHasNoErrors();
    expect($record->fresh()->getAttribute($kind === 'branch' ? 'root_person_id' : 'related_person_id'))->toBe($kind === 'branch' ? $child->id : $otherFather->id);
    $log = AuditLog::query()->findOrFail($change->fresh()->applied_audit_id);
    expect($log->action)->toBe('updated');
    expect($log->before)->not->toBe($log->after);
})->with(['branch', 'relationship']);
