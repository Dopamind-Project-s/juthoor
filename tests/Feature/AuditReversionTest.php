<?php

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
function reversionContext(): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة استرجاع', 'slug' => 'reversion', 'is_active' => true]);
    $tree = $family->familyTrees()->create(['name_ar' => 'شجرة', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $admin = User::factory()->create();
    $reader = User::factory()->create();
    foreach ([[$admin, 'admin'], [$reader, 'reader']] as [$u,$role]) {
        $family->memberships()->create(['user_id' => $u->id, 'role' => $role]);
    }
    $person = $tree->people()->create(['first_name_ar' => 'اسم', 'full_name_ar' => 'اسم أصلي', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $audit = app(AuditLogger::class);
    $log = $audit->record($admin, $family, $person, 'created', null, $audit->snapshot($person));

    return compact('family', 'tree', 'admin', 'reader', 'person', 'audit', 'log');
}
test('reversion displays impact requires explicit confirmation and records a linked new change', function () {
    extract(reversionContext());
    $url = route('management.audit.revert.store', $log->id);
    $this->actingAs($reader)->post($url, ['reason' => 'خطأ', 'confirm' => 1])->assertForbidden();
    $this->actingAs($admin)->get(route('management.audit.revert.preview', $log->id))->assertSee('الأثر: حذف السجل')->assertSee('اسم أصلي');
    $this->post($url, ['reason' => 'تصحيح'])->assertSessionHasErrors('confirm');
    $this->post($url, ['reason' => 'إنشاء غير مقصود', 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertModelMissing($person);
    $this->assertModelExists($log);
    $this->assertDatabaseHas('audit_logs', ['reverted_from_id' => $log->id, 'action' => 'reverted', 'reason' => 'إنشاء غير مقصود']);
});
test('later changes block reversion even if values are restored', function () {
    extract(reversionContext());
    $before = $audit->snapshot($person);
    $person->update(['full_name_ar' => 'تعديل لاحق']);
    $audit->record($admin, $family, $person, 'updated', $before, $audit->snapshot($person));
    $person->update(['full_name_ar' => 'اسم أصلي']);
    $this->actingAs($admin)->post(route('management.audit.revert.store', $log->id), ['reason' => 'استرجاع', 'confirm' => 1])->assertSessionHasErrors('change');
    $this->assertModelExists($person);
});
test('new root or branch dependencies prevent deleting a created person', function () {
    extract(reversionContext());
    $tree->update(['root_person_id' => $person->id]);
    $this->actingAs($admin)->get(route('management.audit.revert.preview', $log->id))->assertSee('لا يمكن حذف جذر الشجرة')->assertDontSee('تأكيد الاسترجاع');
    $this->post(route('management.audit.revert.store', $log->id), ['reason' => 'استرجاع', 'confirm' => 1])->assertSessionHasErrors('person');
    $this->assertModelExists($person);
});
test('reverting an update restores data without erasing history and cannot run twice', function () {
    extract(reversionContext());
    $before = $audit->snapshot($person);
    $person->update(['full_name_ar' => 'اسم محدث']);
    $update = $audit->record($admin, $family, $person, 'updated', $before, $audit->snapshot($person));
    $this->actingAs($admin)->post(route('management.audit.revert.store', $update->id), ['reason' => 'تصحيح الاسم', 'confirm' => 1])->assertSessionHasNoErrors();
    expect($person->fresh()->full_name_ar)->toBe('اسم أصلي');
    $this->post(route('management.audit.revert.store', $update->id), ['reason' => 'مرة أخرى', 'confirm' => 1])->assertSessionHasErrors('change');
    expect(AuditLog::query()->where('reverted_from_id', $update->id)->count())->toBe(1);
});
test('reversion cannot cross family authorization or restore a cyclic father relationship', function () {
    extract(reversionContext());
    $child = $tree->people()->create(['first_name_ar' => 'ابن', 'full_name_ar' => 'ابن', 'status' => 'historical', 'verification_status' => 'verified']);
    $edge = $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $person->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $edgeLog = $audit->record($admin, $family, $edge, 'created', null, $audit->snapshot($edge));
    $family2 = Family::query()->create(['name_ar' => 'عائلة ثانية', 'slug' => 'other']);
    $outsider = User::factory()->create();
    $family2->memberships()->create(['user_id' => $outsider->id, 'role' => 'admin']);
    $this->actingAs($outsider)->get(route('management.audit.revert.preview', $edgeLog->id))->assertForbidden();
    $branch = $tree->branches()->create(['root_person_id' => $child->id, 'name_ar' => 'فرع نشط', 'slug' => 'child', 'is_active' => true, 'verification_status' => 'verified']);
    $this->actingAs($admin)->post(route('management.audit.revert.store', $edgeLog->id), ['reason' => 'استرجاع', 'confirm' => 1])->assertSessionHasErrors('relationship');
    $this->assertModelExists($edge);
});

test('a deleted original record has a clear blocked preview instead of a restore action', function () {
    extract(reversionContext());
    $person->delete();
    $this->actingAs($admin)->get(route('management.audit.revert.preview', $log->id))->assertOk()->assertSee('سجل العملية محذوف')->assertDontSee('تأكيد الاسترجاع');
});
