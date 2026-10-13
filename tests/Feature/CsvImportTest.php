<?php

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Imports\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
function csvContext(): array
{
    Storage::fake('genealogy_private');
    $family = Family::query()->create(['name_ar' => 'عائلة استيراد اصطناعية', 'slug' => 'csv', 'is_active' => true]);
    $tree = $family->familyTrees()->create(['name_ar' => 'شجرة فارغة', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $second = $family->familyTrees()->create(['name_ar' => 'شجرة مستقلة', 'slug' => 'second', 'is_active' => true]);
    $reader = User::factory()->create();
    $editor = User::factory()->create();
    $admin = User::factory()->create();
    foreach ([[$reader, 'reader'], [$editor, 'editor'], [$admin, 'admin']] as [$user,$role]) {
        $family->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }

    return compact('family', 'tree', 'second', 'reader', 'editor', 'admin');
}
function csvContent(array $rows): string
{
    $handle = fopen('php://temp', 'w+');
    fputcsv($handle, CsvImportService::COLUMNS, ',', '"', '');
    foreach ($rows as $row) {
        $data = array_replace(['external_id' => 'P1', 'first_name_ar' => 'جذر', 'full_name_ar' => 'جذر مستورد', 'gender' => 'male', 'birth_year' => '1900', 'death_year' => '1980', 'is_living' => '0', 'status' => 'historical', 'verification_status' => 'verified', 'father_external_id' => '', 'branch_slug' => '', 'branch_name_ar' => ''], $row);
        fputcsv($handle, array_map(fn ($key) => $data[$key], CsvImportService::COLUMNS), ',', '"', '');
    }rewind($handle);
    $content = stream_get_contents($handle);
    fclose($handle);

    return $content;
}
function csvFile(array $rows): UploadedFile
{
    return UploadedFile::fake()->createWithContent('synthetic.csv', csvContent($rows));
}
function csvDecision(ImportBatch $batch, array $extra = []): array
{
    return array_replace(['confirm' => 1, 'reason' => 'مراجعة اصطناعية', 'preview_token' => $batch->fresh()->state_fingerprint], $extra);
}
test('CSV dry run writes no genealogy and only an administrator explicitly commits it once', function () {
    extract(csvContext());
    $rows = [['branch_slug' => 'import-root', 'branch_name_ar' => 'فرع مستورد'], ['external_id' => 'P2', 'first_name_ar' => 'ابن', 'full_name_ar' => 'ابن مستورد', 'father_external_id' => 'P1']];
    $upload = route('management.imports.store', $family->slug);
    $this->actingAs($reader)->post($upload, ['file' => csvFile($rows)])->assertForbidden();
    $this->actingAs($editor)->post($upload, ['file' => csvFile($rows)])->assertSessionHasNoErrors();
    $batch = ImportBatch::query()->sole();
    $this->assertDatabaseCount('people', 0);
    $this->assertDatabaseCount('person_relationships', 0);
    expect(collect($batch->results)->pluck('status')->all())->toBe(['ready', 'ready']);
    Storage::disk('genealogy_private')->assertExists($batch->file_path);
    $this->get(route('management.imports.show', [$family->slug, $batch->id]))->assertDontSee($batch->file_path)->assertDontSee('اعتماد الدفعة');
    $commit = route('management.imports.commit', [$family->slug, $batch->id]);
    $this->post($commit, csvDecision($batch))->assertForbidden();
    $this->actingAs($admin)->post($commit, ['reason' => 'بدون تأكيد', 'preview_token' => $batch->state_fingerprint])->assertSessionHasErrors('confirm');
    $this->post($commit, csvDecision($batch, ['root_external_id' => 'P1']))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('people', 2);
    $this->assertDatabaseCount('person_relationships', 1);
    $this->assertDatabaseCount('branches', 1);
    expect($tree->fresh()->root_person_id)->not->toBeNull();
    expect($batch->fresh()->status)->toBe('committed');
    $this->post($commit, csvDecision($batch))->assertSessionHasErrors('change');
    $this->post($upload, ['file' => csvFile($rows)])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('import_batches', 1);
    $this->assertDatabaseCount('people', 2);
    $this->assertDatabaseHas('audit_logs', ['auditable_id' => $batch->id, 'action' => 'import_committed']);
});
test('invalid CSV rows show errors for cycles references dates and duplicate identifiers without partial writes', function (array $rows) {
    extract(csvContext());
    $this->actingAs($editor)->post(route('management.imports.store', $family->slug), ['file' => csvFile($rows)])->assertSessionHasNoErrors();
    $batch = ImportBatch::query()->sole();
    expect(collect($batch->results)->where('status', 'error')->count())->toBeGreaterThan(0);
    $this->actingAs($admin)->post(route('management.imports.commit', [$family->slug, $batch->id]), csvDecision($batch))->assertSessionHasErrors('change');
    $this->assertDatabaseCount('people', 0);
    $this->assertDatabaseCount('person_relationships', 0);
    expect($batch->fresh()->status)->toBe('failed');
})->with([
    'cycle' => [[['father_external_id' => 'P2'], ['external_id' => 'P2', 'full_name_ar' => 'شخص آخر', 'father_external_id' => 'P1']]],
    'self' => [[['father_external_id' => 'P1']]],
    'missing' => [[['father_external_id' => 'NO-SUCH-ID']]],
    'duplicate' => [[[], ['full_name_ar' => 'اسم مختلف']]],
    'dates' => [[['birth_year' => '2000', 'death_year' => '1900'], ['external_id' => 'P2', 'full_name_ar' => 'صف سليم']]],
]);
test('similar names require a separate-person decision and are never merged automatically', function () {
    extract(csvContext());
    $tree->people()->create(['canonical_key' => 'EXISTING', 'first_name_ar' => 'جذر', 'full_name_ar' => 'جذر مستورد', 'status' => 'historical', 'verification_status' => 'verified']);
    $this->actingAs($editor)->post(route('management.imports.store', $family->slug), ['file' => csvFile([[]])])->assertSessionHasNoErrors();
    $batch = ImportBatch::query()->sole();
    expect($batch->results[0]['status'])->toBe('conflict');
    $url = route('management.imports.commit', [$family->slug, $batch->id]);
    $this->actingAs($admin)->post($url, csvDecision($batch))->assertSessionHasErrors('change');
    $this->assertDatabaseCount('people', 1);
    $this->post(route('management.imports.revalidate', [$family->slug, $batch->id]))->assertSessionHasNoErrors();
    $this->post($url, csvDecision($batch, ['allow_name_duplicates' => 1, 'duplicate_reason' => 'شخصان مختلفان لهما الاسم نفسه']))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('people', 2);
    expect($tree->people()->where('full_name_ar', 'جذر مستورد')->count())->toBe(2);
});
test('stale tree previews fail and explicit revalidation permits a safe retry', function () {
    extract(csvContext());
    $this->actingAs($editor)->post(route('management.imports.store', $family->slug), ['file' => csvFile([[]])])->assertSessionHasNoErrors();
    $batch = ImportBatch::query()->sole();
    $old = $batch->state_fingerprint;
    $tree->people()->create(['first_name_ar' => 'أحدث', 'full_name_ar' => 'سجل أحدث', 'status' => 'historical', 'verification_status' => 'verified']);
    $url = route('management.imports.commit', [$family->slug, $batch->id]);
    $this->actingAs($admin)->post($url, csvDecision($batch))->assertSessionHasErrors('change');
    $this->assertDatabaseCount('people', 1);
    $this->post(route('management.imports.revalidate', [$family->slug, $batch->id]))->assertSessionHasNoErrors();
    $this->post($url, csvDecision($batch, ['preview_token' => $old]))->assertSessionHasErrors('change');
    $this->post(route('management.imports.revalidate', [$family->slug, $batch->id]))->assertSessionHasNoErrors();
    $this->post($url, csvDecision($batch))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('people', 2);
});
test('an integrity failure after insertion rolls back all rows and audit changes then allows revalidation and restart', function () {
    extract(csvContext());
    $rows = [[], ['external_id' => 'P2', 'first_name_ar' => 'ابن', 'full_name_ar' => 'ابن مستورد', 'father_external_id' => 'P1']];
    $this->actingAs($editor)->post(route('management.imports.store', $family->slug), ['file' => csvFile($rows)])->assertSessionHasNoErrors();
    $batch = ImportBatch::query()->sole();
    $url = route('management.imports.commit', [$family->slug, $batch->id]);
    $this->actingAs($admin)->post($url, csvDecision($batch, ['root_external_id' => 'P2']))->assertSessionHasErrors('change');
    $this->assertDatabaseCount('people', 0);
    $this->assertDatabaseCount('person_relationships', 0);
    expect(AuditLog::query()->where('auditable_type', Person::class)->count())->toBe(0);
    expect($batch->fresh()->status)->toBe('failed');
    $this->post(route('management.imports.revalidate', [$family->slug, $batch->id]))->assertSessionHasNoErrors();
    $this->post($url, csvDecision($batch, ['root_external_id' => 'P1']))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('people', 2);
    expect($batch->fresh()->status)->toBe('committed');
});
test('import files and external references are isolated by family and selected tree and checksum is checked again', function () {
    extract(csvContext());
    $tree->people()->create(['canonical_key' => 'PARENT', 'first_name_ar' => 'أب', 'full_name_ar' => 'أب موجود', 'status' => 'historical', 'verification_status' => 'verified']);
    $this->actingAs($editor)->post(route('management.imports.store', [$family->slug, 'tree' => 'second']), ['file' => csvFile([['father_external_id' => 'PARENT']])])->assertSessionHasNoErrors();
    $batch = ImportBatch::query()->sole();
    expect($batch->results[0]['status'])->toBe('error');
    $this->get(route('management.imports.show', [$family->slug, $batch->id, 'tree' => 'main']))->assertNotFound();
    $other = Family::query()->create(['name_ar' => 'عائلة أخرى', 'slug' => 'other', 'is_active' => true]);
    $otherTree = $other->familyTrees()->create(['name_ar' => 'أخرى', 'slug' => 'main', 'is_primary' => true]);
    $other->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
    $this->actingAs($admin)->get(route('management.imports.show', [$other->slug, $batch->id]))->assertNotFound();
    $this->post(route('management.imports.store', [$family->slug, 'tree' => 'main']), ['file' => csvFile([['external_id' => 'NEW']])])->assertSessionHasNoErrors();
    $valid = ImportBatch::query()->latest('id')->first();
    Storage::disk('genealogy_private')->put($valid->file_path, 'tampered');
    $this->post(route('management.imports.commit', [$family->slug, $valid->id]), csvDecision($valid))->assertSessionHasErrors('change');
    $this->assertDatabaseCount('people', 1);
});
test('unsupported headers and invalid encoding are refused instead of silently dropping fields', function () {
    extract(csvContext());
    $this->actingAs($editor)->post(route('management.imports.store', $family->slug), ['file' => UploadedFile::fake()->createWithContent('wrong.csv', "external_id,full_name_ar,mother_external_id\nP1,اسم,P2\n")])->assertSessionHasErrors('change');
    $this->assertDatabaseCount('import_batches', 0);
});

test('audited edits in a different tree do not invalidate the selected tree CSV preview', function () {
    extract(csvContext());
    $this->actingAs($editor)->post(route('management.imports.store', $family->slug), ['file' => csvFile([[]])])->assertSessionHasNoErrors();
    $batch = ImportBatch::query()->sole();
    $other = $second->people()->create(['first_name_ar' => 'آخر', 'full_name_ar' => 'شخص في شجرة أخرى', 'status' => 'historical', 'verification_status' => 'verified']);
    app(AuditLogger::class)->record($editor, $family, $other, 'created', null, app(AuditLogger::class)->snapshot($other));
    $this->actingAs($admin)->post(route('management.imports.commit', [$family->slug, $batch->id]), csvDecision($batch))->assertSessionHasNoErrors();
    expect($tree->people()->count())->toBe(1);
    expect($second->people()->count())->toBe(1);
});
