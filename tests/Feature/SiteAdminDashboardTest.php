<?php

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function siteDashboardSuper(): User
{
    $super = User::factory()->create()->forceFill(['is_super_admin' => true]);
    $super->save();

    return $super;
}

test('family administrators and readers cannot open the site dashboard', function (string $role) {
    $family = Family::query()->create(['name_ar' => 'عائلة', 'slug' => 'family']);
    $user = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $user->id, 'role' => $role]);

    $this->actingAs($user)->get(route('site-admin.dashboard'))->assertForbidden();
})->with(['admin', 'reader']);

test('the site dashboard reports global counts pending work and system health', function () {
    $super = siteDashboardSuper();
    User::factory()->create(['is_active' => false]);
    User::factory()->unverified()->create();

    $this->actingAs($super)->get(route('site-admin.dashboard'))
        ->assertOk()
        ->assertSee('صحة النظام')->assertSee('النسخ الاحتياطي')->assertSee('المهام الفاشلة')
        ->assertViewHas('statistics', fn (array $statistics) => $statistics['users'] === 3
            && $statistics['inactive_users'] === 1
            && $statistics['unverified_users'] === 1
            && $statistics['super_admins'] === 1
            && $statistics['failed_jobs'] === 0)
        ->assertSee('يوجد سوبر أدمن نشط واحد فقط');
});

test('failed jobs raise an operational alert on the site dashboard', function () {
    DB::table('failed_jobs')->insert(['uuid' => 'job-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

    $this->actingAs(siteDashboardSuper())->get(route('site-admin.dashboard'))
        ->assertOk()
        ->assertSee('مشكلات تشغيلية تحتاج إلى تدخل')
        ->assertViewHas('statistics', fn (array $statistics) => $statistics['failed_jobs'] === 1);
});

test('active families without an active administrator primary tree or root are flagged', function () {
    $healthy = Family::query()->create(['name_ar' => 'عائلة سليمة', 'slug' => 'healthy']);
    $tree = $healthy->familyTrees()->create(['name_ar' => 'شجرة', 'slug' => 'main', 'is_primary' => true]);
    $tree->update(['root_person_id' => $tree->people()->create(['first_name_ar' => 'جد', 'full_name_ar' => 'جد'])->id]);
    $admin = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $healthy->id, 'user_id' => $admin->id, 'role' => 'admin']);
    $orphaned = Family::query()->create(['name_ar' => 'عائلة دون مدير', 'slug' => 'orphaned']);
    $disabledAdmin = User::factory()->create(['is_active' => false]);
    FamilyMembership::query()->create(['family_id' => $orphaned->id, 'user_id' => $disabledAdmin->id, 'role' => 'admin']);
    Family::query()->create(['name_ar' => 'عائلة معطلة', 'slug' => 'disabled', 'is_active' => false]);

    $this->actingAs(siteDashboardSuper())->get(route('site-admin.dashboard'))
        ->assertOk()
        ->assertViewHas('attention', fn (array $rows) => collect($rows)->pluck('family.slug')->all() === ['orphaned']
            && $rows[0]['issues'] === ['لا يوجد مدير عائلة نشط', 'لا توجد شجرة أساسية']);
});
