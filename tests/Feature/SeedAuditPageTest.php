<?php

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\LegacyGenealogySeeder;

uses(RefreshDatabase::class);

test('the seed audit page is read-only and reserved for super admins', function () {
    $this->seed(LegacyGenealogySeeder::class);
    $family = Family::query()->where('slug', 'al-zoubi')->sole();
    $admin = User::factory()->create(['is_active' => true]);
    FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $admin->id, 'role' => 'admin']);
    $this->actingAs($admin)->get(route('site-admin.seed-audit'))->assertForbidden();

    $superAdmin = User::factory()->create(['is_active' => true, 'is_super_admin' => true]);
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) && ! str_contains($query->sql, '"sessions"') && ! str_contains($query->sql, '"cache')) {
            $writes[] = $query->sql;
        }
    });
    $this->actingAs($superAdmin)->get(route('site-admin.seed-audit', ['family' => 'al-zoubi']))->assertOk()
        ->assertSee('تدقيق ما قبل التشغيل')->assertSee('الفروع والمؤسسون')->assertSee('بكر')->assertSee('ليست تعدادًا للسكان');
    expect($writes)->toBe([]);
});
