<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Role;
use App\Models\User;
use App\Services\Imports\FamilyBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manifest = [
        'family' => ['slug' => 'al-test', 'name_ar' => 'عائلة الاختبار', 'location' => 'إربد'],
        'sources' => [['reference' => 'REF-1', 'title' => 'وثيقة العائلة']],
        'entities' => [['slug' => 'clan', 'name_ar' => 'العشيرة', 'entity_type' => 'clan'], ['slug' => 'fakhd-a', 'name_ar' => 'فخذ أ', 'entity_type' => 'fakhd', 'parent' => 'clan']],
        'branches' => [['slug' => 'a', 'name_ar' => 'فرع أ'], ['slug' => 'b', 'name_ar' => 'فرع ب', 'parent' => 'a', 'founder_key' => 'NOT-THERE']],
    ];
});

test('minimal bootstrap: family, main tree, role templates and drafts, with no fabricated genealogy', function () {
    $report = app(FamilyBootstrapService::class)->bootstrap(['family' => ['slug' => 'minimal', 'name_ar' => 'عائلة صغيرة']]);
    $family = Family::query()->where('slug', 'minimal')->sole();
    expect($family->is_active)->toBeFalse()
        ->and($family->familyTrees()->sole()->is_primary)->toBeTrue()
        ->and($family->familyTrees()->sole()->root_person_id)->toBeNull()
        ->and(Role::query()->where('is_system', true)->count())->toBeGreaterThan(0)
        ->and(Person::query()->count())->toBe(0)->and(PersonRelationship::query()->count())->toBe(0)
        ->and($report['created'])->toContain('tree: main');
});

test('full manifest: drafts and pending branches only; undocumented founders are skipped, not invented', function () {
    $admin = User::factory()->create(['email' => 'lead@example.test', 'is_active' => true]);
    $report = app(FamilyBootstrapService::class)->bootstrap($this->manifest + ['administrator_email' => 'lead@example.test']);
    $family = Family::query()->where('slug', 'al-test')->sole();
    expect(CommunityEntity::query()->where('family_id', $family->id)->pluck('publication_status')->unique()->all())->toBe(['draft'])
        ->and(CommunityEntity::query()->where('slug', 'fakhd-a')->sole()->parent->slug)->toBe('clan')
        ->and(Branch::query()->where('slug', 'a')->sole()->root_person_id)->toBeNull()
        ->and(Branch::query()->where('slug', 'a')->sole()->is_active)->toBeFalse()
        ->and(Branch::query()->where('slug', 'b')->exists())->toBeFalse()
        ->and(collect($report['skipped'])->implode(' '))->toContain('NOT-THERE')
        ->and($family->sources()->sole()->verification_status)->toBe('pending_review')
        ->and($admin->roleFor($family))->toBe('admin')
        ->and(Person::query()->count())->toBe(0);
    // A documented founder that exists is linked.
    $founder = $family->familyTrees()->sole()->people()->create(['canonical_key' => 'F-1', 'first_name_ar' => 'مؤسس', 'full_name_ar' => 'مؤسس', 'status' => 'historical', 'verification_status' => 'pending_review']);
    app(FamilyBootstrapService::class)->bootstrap(['family' => ['slug' => 'al-test', 'name_ar' => 'x'], 'branches' => [['slug' => 'c', 'name_ar' => 'فرع ج', 'founder_key' => 'F-1']]]);
    expect(Branch::query()->where('slug', 'c')->sole()->root_person_id)->toBe($founder->id);
});

test('rerun keeps administrator decisions and existing roles; dry run writes nothing', function () {
    app(FamilyBootstrapService::class)->bootstrap($this->manifest);
    $family = Family::query()->where('slug', 'al-test')->sole();
    $family->update(['name_ar' => 'الاسم المعتمد', 'is_active' => true]);
    Branch::query()->where('slug', 'a')->update(['name_ar' => 'فرع أ المعدل']);
    $member = User::factory()->create(['email' => 'member@example.test']);
    $family->memberships()->create(['user_id' => $member->id, 'role' => 'reader']);
    $counts = fn () => [Family::query()->count(), CommunityEntity::query()->count(), Branch::query()->count(), AuditLog::query()->count()];
    $before = $counts();

    $report = app(FamilyBootstrapService::class)->bootstrap($this->manifest + ['administrator_email' => 'member@example.test']);
    expect($family->fresh()->name_ar)->toBe('الاسم المعتمد')->and($family->fresh()->is_active)->toBeTrue()
        ->and(Branch::query()->where('slug', 'a')->sole()->name_ar)->toBe('فرع أ المعدل')
        ->and($member->roleFor($family))->toBe('reader')
        ->and($report['created'])->toBe([])
        ->and($counts())->toBe($before);

    $dry = app(FamilyBootstrapService::class)->bootstrap(['family' => ['slug' => 'dry', 'name_ar' => 'تجريبي']], dryRun: true);
    expect($dry['created'])->not->toBe([])->and(Family::query()->where('slug', 'dry')->exists())->toBeFalse();
});

test('invalid manifests are refused before any write; the command reports and exits non-zero', function () {
    expect(fn () => app(FamilyBootstrapService::class)->bootstrap(['family' => ['slug' => 'bad slug!', 'name_ar' => '']]))->toThrow(ValidationException::class);
    expect(Family::query()->count())->toBe(0);
    $path = tempnam(sys_get_temp_dir(), 'manifest');
    file_put_contents($path, json_encode($this->manifest));
    $this->artisan('family:bootstrap', ['manifest' => $path, '--dry-run' => true])->assertSuccessful();
    expect(Family::query()->count())->toBe(0);
    $this->artisan('family:bootstrap', ['manifest' => $path])->assertSuccessful();
    expect(Family::query()->where('slug', 'al-test')->exists())->toBeTrue();
    file_put_contents($path, '{not json');
    $this->artisan('family:bootstrap', ['manifest' => $path])->assertFailed();
    unlink($path);
});
