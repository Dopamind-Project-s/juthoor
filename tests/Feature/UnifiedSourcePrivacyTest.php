<?php

use App\Models\Citation;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->family = Family::create(['slug' => 'private-sources', 'name_ar' => 'عائلة المصادر', 'is_active' => true]);
    $this->tree = $this->family->familyTrees()->create(['slug' => 'main', 'name_ar' => 'الشجرة', 'is_primary' => true, 'is_active' => true]);
    $this->source = $this->family->sources()->create(['reference' => 'unified:private-extraction', 'title' => 'PRIVATE-RAW-SOURCE', 'source_type' => 'document', 'verification_status' => 'pending_review']);
    Citation::create(['source_id' => $this->source->id, 'family_tree_id' => $this->tree->id, 'field_key' => 'source_observation', 'locator' => 'unified-review:one', 'notes' => 'PRIVATE-UNRESOLVED-NAME', 'verification_status' => 'unresolved']);
});

test('ordinary family readers cannot enumerate or read raw unified observations', function () {
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->family->memberships()->create(['user_id' => $user->id, 'role' => FamilyMembership::ROLE_READER]);
    $this->actingAs($user)->get('/manage/families/private-sources/sources')->assertOk()->assertDontSee('PRIVATE-RAW-SOURCE');
    $this->get('/manage/families/private-sources/sources/'.$this->source->id)->assertForbidden();
    $this->get('/manage/families/private-sources/sources/'.$this->source->id.'/download')->assertForbidden();
});

test('a family-scoped genealogy reviewer can inspect unresolved source observations', function () {
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $membership = $this->family->memberships()->create(['user_id' => $user->id, 'role' => FamilyMembership::ROLE_READER]);
    FamilyRoleAssignment::create(['family_membership_id' => $membership->id, 'role_id' => Role::where('key', 'genealogy-reviewer')->sole()->id]);
    $this->actingAs($user)->get('/manage/families/private-sources/sources/'.$this->source->id)->assertOk()->assertSee('PRIVATE-UNRESOLVED-NAME');
    $this->get('/manage/families/private-sources/sources')->assertOk()->assertSee('PRIVATE-RAW-SOURCE');
});
