<?php

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siteFamily(string $slug): Family
{
    $family = Family::query()->create(['name_ar' => 'عائلة '.$slug, 'slug' => $slug]);
    $family->familyTrees()->create(['name_ar' => 'شجرة '.$slug, 'slug' => 'main', 'is_primary' => true]);

    return $family;
}

test('only a super admin can access site administration across independent families', function () {
    $first = siteFamily('one');
    $second = siteFamily('two');
    $manager = User::factory()->create();
    $super = User::factory()->create()->forceFill(['is_super_admin' => true]);
    $super->save();
    $super->refresh();
    FamilyMembership::query()->create(['family_id' => $first->id, 'user_id' => $manager->id, 'role' => 'admin']);

    $this->actingAs($manager)->get(route('site-admin.dashboard'))->assertForbidden();
    $this->actingAs($super)->get(route('site-admin.dashboard'))
        ->assertOk()->assertSee($first->name_ar)->assertSee($second->name_ar);
    $this->actingAs($super)->get(route('management.families.show', $second->slug))->assertOk();
});

test('family managers cannot grant site administration while a super admin can and the change is audited', function () {
    $family = siteFamily('roles');
    $manager = User::factory()->create();
    $target = User::factory()->create();
    $super = User::factory()->create()->forceFill(['is_super_admin' => true]);
    $super->save();
    $super->refresh();
    FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $manager->id, 'role' => 'admin']);

    $this->actingAs($manager)->put(route('site-admin.users.update', $target->id), [
        'is_active' => 1, 'is_super_admin' => 1,
    ])->assertForbidden();
    $this->actingAs($super)->put(route('site-admin.users.update', $target->id), [
        'is_active' => 1, 'is_super_admin' => 1,
    ])->assertSessionHasNoErrors();

    expect($target->refresh()->isSuperAdmin())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'super_admin_changed')->where('auditable_id', $target->id)->exists())->toBeTrue();
});

test('the last active super admin cannot be disabled or demoted', function () {
    $super = User::factory()->create()->forceFill(['is_super_admin' => true, 'is_active' => true]);
    $super->save();
    $super->refresh();

    $this->actingAs($super)->put(route('site-admin.users.update', $super->id), [
        'is_active' => 0, 'is_super_admin' => 0,
    ])->assertSessionHasErrors(['user' => 'لا يمكن تعطيل أو سحب صلاحية آخر سوبر أدمن نشط.']);

    $this->actingAs($super)->delete(route('site-admin.users.destroy', $super->id))
        ->assertSessionHasErrors(['user' => 'لا يمكن حذف آخر سوبر أدمن نشط.']);

    expect($super->refresh()->isSuperAdmin())->toBeTrue();
});

test('the bootstrap command creates a verified super admin without a seeded password', function () {
    $this->artisan('site:make-super-admin', [
        'email' => 'owner@example.test', '--name' => 'مالك الموقع', '--password' => 'a-secure-password',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'owner@example.test')->sole();
    expect($user->isSuperAdmin())->toBeTrue()->and($user->hasVerifiedEmail())->toBeTrue();
});

test('a super admin creates a family and manages multiple trees with audit history', function () {
    $super = User::factory()->create()->forceFill(['is_super_admin' => true, 'is_active' => true]);
    $super->save();
    $super->refresh();

    $this->actingAs($super)->post(route('site-admin.families.store'), [
        'name_ar' => 'عائلة جديدة', 'slug' => 'new-family', 'tree_name_ar' => 'الشجرة الأولى',
    ])->assertSessionHasNoErrors();
    $family = Family::query()->where('slug', 'new-family')->sole();
    $this->actingAs($super)->post(route('site-admin.trees.store', $family->id), [
        'name_ar' => 'شجرة ثانية', 'slug' => 'second',
    ])->assertSessionHasNoErrors();

    expect($family->familyTrees()->count())->toBe(2)
        ->and(AuditLog::query()->where('family_id', $family->id)->count())->toBe(2);
});
