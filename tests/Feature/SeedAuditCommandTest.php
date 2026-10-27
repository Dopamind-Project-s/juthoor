<?php

use App\Models\Family;
use App\Models\Person;
use App\Models\Source;
use App\Services\Imports\SeedAuditService;
use Database\Seeders\AlQawasmehBranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** @return array<string, array{int, string|null}> */
function seedAuditFingerprint(): array
{
    $tables = ['families', 'family_trees', 'people', 'person_relationships', 'branches', 'sources', 'citations', 'assertions', 'person_sources', 'audit_logs', 'person_attributes'];

    return collect($tables)->mapWithKeys(fn (string $table) => [$table => [
        DB::table($table)->count(),
        Schema::hasColumn($table, 'updated_at') ? DB::table($table)->max('updated_at') : null,
    ]])->all();
}

test('the dry-run audit on an empty database reports missing dependencies and writes nothing', function () {
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|alter|create|drop)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('genealogy:seed-audit', ['--dry-run' => true])->assertExitCode(1);

    expect($writes)->toBe([])
        ->and(Family::query()->count())->toBe(0);
});

test('the dry-run audit on seeded data reports counts checksums and privacy without any mutation', function () {
    $this->seed();
    $this->seed(AlQawasmehBranchSeeder::class);
    $before = seedAuditFingerprint();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|alter|create|drop)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('genealogy:seed-audit', ['--dry-run' => true])->expectsOutputToContain('لم يُعدَّل أي سجل.')->assertSuccessful();
    $report = app(SeedAuditService::class)->report();

    expect($writes)->toBe([])
        ->and(seedAuditFingerprint())->toBe($before)
        ->and($report['read_only'])->toBeTrue()
        ->and($report['identity_conflicts'])->toBe([])
        ->and($report['missing_dependencies'])->toBe([])
        ->and($report['integrity'])->toBe(['self_parent' => [], 'multiple_primary_fathers' => [], 'cycles' => []])
        ->and($report['counts'])->toMatchArray(['people' => 119, 'father_edges' => 118, 'branches' => 6, 'founders' => 5])
        ->and($report['expected'])->toMatchArray(['ready_root' => 77, 'ready_root_present' => 77, 'qawasmeh' => 34, 'qawasmeh_present' => 34])
        ->and($report['privacy']['qawasmeh_pending'])->toBe(34)
        ->and($report['privacy']['webroot_datasets'])->toContain('public/docs/juthoor_alzoubi_genealogy_dataset_v4.json')
        ->and(collect($report['datasets'])->firstWhere('label', 'Al-Zoubi genealogy dataset'))->toMatchArray(['exists' => true, 'version' => 'v4'])
        ->and(collect($report['datasets'])->where('label', 'Qawasmeh attachment')->pluck('matches')->unique()->all())->toBe([true])
        ->and($report['preserved_edits'])->toBe([])
        ->and($report['duplicate_candidates'])->toBe([])
        ->and($report['status'])->toBe(SeedAuditService::STATUS_WARNINGS);
});

test('the audit reports preserved edits identity conflicts duplicates and integrity problems', function () {
    $this->seed();
    $family = Family::query()->where('slug', 'al-zoubi')->sole();
    $tree = $family->primaryTree;
    $family->update(['name_ar' => 'اسم إداري']);
    $tree->people()->where('canonical_key', 'ROOT-010')->sole()->update(['verification_status' => Source::VERIFICATION_VERIFIED, 'full_name_ar' => 'اسم مختلف']);
    $father = $tree->people()->where('canonical_key', 'ROOT-020')->sole();
    $twin = $tree->people()->create(['first_name_ar' => 'نسخة', 'full_name_ar' => 'نسخة مكررة']);
    $copy = $tree->people()->create(['first_name_ar' => 'نسخة', 'full_name_ar' => 'نسخة مكررة']);
    foreach ([$twin, $copy] as $child) {
        $child->outgoingRelationships()->create(['family_tree_id' => $tree->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true]);
    }
    // A cycle written behind the integrity service, as a raw import or manual edit could.
    $root = $tree->people()->where('canonical_key', 'ROOT-001')->sole();
    DB::table('person_relationships')->insert(['family_tree_id' => $tree->id, 'person_id' => $root->id, 'related_person_id' => $tree->people()->where('canonical_key', 'ROOT-003')->value('id'), 'relationship_type' => 'father', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

    $report = app(SeedAuditService::class)->report('al-zoubi');

    expect($report['status'])->toBe(SeedAuditService::STATUS_CONFLICTS)
        ->and($report['preserved_edits'])->toContain(['record' => 'family', 'key' => 'al-zoubi', 'field' => 'name_ar', 'current' => 'اسم إداري', 'seed' => 'عشيرة الزعبي'])
        ->and(collect($report['preserved_edits'])->where('key', 'ROOT-010')->pluck('field')->all())->toBe(['verification_status'])
        ->and(collect($report['identity_conflicts'])->firstWhere('key', 'ROOT-010'))->toMatchArray(['type' => 'name', 'existing' => 'اسم مختلف'])
        ->and(collect($report['duplicate_candidates'])->firstWhere('name', 'نسخة مكررة')['people'])->toHaveCount(2)
        ->and($report['integrity']['cycles'])->not->toBe([]);
    $this->artisan('genealogy:seed-audit', ['--json' => true])->assertExitCode(1);
});

test('the audit of another family skips curated dataset comparisons', function () {
    $family = Family::query()->create(['name_ar' => 'عائلة أخرى', 'slug' => 'other', 'is_active' => true]);
    $tree = $family->familyTrees()->create(['name_ar' => 'شجرة', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $tree->people()->create(['first_name_ar' => 'أ', 'full_name_ar' => 'أ']);

    $report = app(SeedAuditService::class)->report('other');

    expect($report['status'])->toBe(SeedAuditService::STATUS_WARNINGS)
        ->and($report['datasets'])->toBe([])
        ->and($report['counts']['people'])->toBe(1)
        ->and(Person::query()->count())->toBe(1);
});
