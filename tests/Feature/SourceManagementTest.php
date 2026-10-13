<?php

use App\Models\Citation;
use App\Models\Family;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
function sourceContext(): array
{
    Storage::fake('genealogy_private');
    $family = Family::query()->create(['name_ar' => 'عائلة المصادر', 'slug' => 'sources', 'is_active' => true]);
    $tree = $family->familyTrees()->create(['name_ar' => 'الأولى', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $second = $family->familyTrees()->create(['name_ar' => 'الثانية', 'slug' => 'second', 'is_active' => true]);
    $editor = User::factory()->create();
    $reader = User::factory()->create();
    foreach ([[$editor, 'editor'], [$reader, 'reader']] as [$u,$role]) {
        $family->memberships()->create(['user_id' => $u->id, 'role' => $role]);
    }
    $person = $tree->people()->create(['first_name_ar' => 'شخص', 'full_name_ar' => 'شخص اصطناعي', 'status' => 'historical', 'verification_status' => 'verified']);

    return compact('family', 'tree', 'second', 'editor', 'reader', 'person');
}
function sourceInput(): array
{
    return ['title' => 'وثيقة خاصة', 'source_type' => 'document', 'reference' => 'DOC-1', 'verification_status' => 'pending_review', 'file' => UploadedFile::fake()->createWithContent('private.txt', 'محتوى اصطناعي خاص')];
}
test('editors manage private sources and members download while outsiders and anonymous users cannot', function () {
    extract(sourceContext());
    $this->actingAs($reader)->post(route('management.sources.store', $family->slug), sourceInput())->assertForbidden();
    $this->actingAs($editor)->post(route('management.sources.store', $family->slug), sourceInput())->assertRedirect()->assertSessionHasNoErrors();
    $source = Source::query()->sole();
    Storage::disk('genealogy_private')->assertExists($source->file_path);
    $url = route('management.sources.download', [$family->slug, $source->id]);
    $this->actingAs($reader)->get($url)->assertOk()->assertDownload('source-'.$source->id.'.txt');
    $this->get(route('management.sources.show', [$family->slug, $source->id]))->assertDontSee($source->file_path)->assertDontSee('حذف المصدر غير المرتبط');
    $other = User::factory()->create();
    $this->actingAs($other)->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
    $family->update(['is_active' => false]);
    $this->actingAs($reader)->get($url)->assertNotFound();
});
test('citations link facts or father relationships and preserve family and selected tree boundaries', function () {
    extract(sourceContext());
    $this->actingAs($editor)->post(route('management.sources.store', $family->slug), sourceInput())->assertSessionHasNoErrors();
    $source = Source::query()->sole();
    $payload = ['target_type' => 'person', 'target_id' => $person->id, 'field_key' => 'birth_year', 'locator' => 'صفحة 12', 'verification_status' => 'verified'];
    $url = route('management.sources.cite', [$family->slug, $source->id]);
    $this->post($url, $payload)->assertSessionHasNoErrors();
    $citation = Citation::query()->sole();
    expect($citation->person_id)->toBe($person->id);
    expect($citation->field_key)->toBe('birth_year');
    $this->post(route('management.sources.cite', [$family->slug, $source->id, 'tree' => 'second']), $payload)->assertNotFound();
    $this->get(route('management.sources.show', [$family->slug, $source->id, 'tree' => 'second']))->assertDontSee('صفحة 12');
    $other = Family::query()->create(['name_ar' => 'عائلة ثانية', 'slug' => 'other', 'is_active' => true]);
    $otherTree = $other->familyTrees()->create(['name_ar' => 'ثانية', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $other->memberships()->create(['user_id' => $editor->id, 'role' => 'editor']);
    $this->get(route('management.sources.show', [$other->slug, $source->id]))->assertNotFound();
    $this->delete(route('management.sources.destroy', [$family->slug, $source->id]))->assertSessionHasErrors('source');
    $this->delete(route('management.people.destroy', [$family->slug, $person->id]))->assertSessionHasErrors('person');
    $this->put(route('management.sources.citation.update', [$family->slug, $source->id, $citation->id]), ['locator' => 'صفحة 14', 'verification_status' => 'sourced'])->assertSessionHasNoErrors();
    expect($citation->fresh()->locator)->toBe('صفحة 14');
    $this->delete(route('management.sources.citation.destroy', [$family->slug, $source->id, $citation->id]))->assertSessionHasNoErrors();
    $this->assertModelMissing($citation);
    $this->delete(route('management.sources.destroy', [$family->slug, $source->id]))->assertSessionHasNoErrors();
    Storage::disk('genealogy_private')->assertMissing($source->file_path);
});
test('source validation refuses active uploads javascript links and duplicate family references', function () {
    extract(sourceContext());
    $this->actingAs($editor)->post(route('management.sources.store', $family->slug), array_replace(sourceInput(), ['file' => UploadedFile::fake()->createWithContent('bad.html', '<script>alert(1)</script>')]))->assertSessionHasErrors('file');
    $this->post(route('management.sources.store', $family->slug), array_replace(sourceInput(), ['url' => 'javascript:alert(1)']))->assertSessionHasErrors('url');
    $this->assertDatabaseCount('sources', 0);
    $this->post(route('management.sources.store', $family->slug), sourceInput())->assertSessionHasNoErrors();
    $this->post(route('management.sources.store', $family->slug), sourceInput())->assertSessionHasErrors('reference');
});
test('a relationship citation supports the father fact and guards later relationship deletion', function () {
    extract(sourceContext());
    $child = $tree->people()->create(['first_name_ar' => 'ابن', 'full_name_ar' => 'ابن', 'status' => 'historical', 'verification_status' => 'verified']);
    $edge = $tree->personRelationships()->create(['person_id' => $child->id, 'related_person_id' => $person->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $this->actingAs($editor)->post(route('management.sources.store', $family->slug), sourceInput())->assertSessionHasNoErrors();
    $source = Source::query()->sole();
    $this->post(route('management.sources.cite', [$family->slug, $source->id]), ['target_type' => 'relationship', 'target_id' => $edge->id, 'field_key' => 'father', 'locator' => 'سجل 3', 'verification_status' => 'verified'])->assertSessionHasNoErrors();
    $this->get(route('management.people.show', [$family->slug, $child->id]))->assertSee('وثيقة خاصة')->assertSee('سجل 3');
    $this->delete(route('management.relationships.destroy', [$family->slug, $edge->id]))->assertSessionHasErrors('relationship');
    $this->assertDatabaseHas('citations', ['person_relationship_id' => $edge->id, 'person_id' => null]);
});

test('legacy dataset sources and person links remain editable without exposing or moving legacy files', function () {
    extract(sourceContext());
    $source = $family->sources()->create(['source_type' => 'genealogy_dataset', 'title' => 'مجموعة اصطناعية قديمة', 'reference' => 'LEGACY', 'file_path' => 'docs/synthetic-legacy.json', 'verification_status' => 'sourced']);
    $person->sources()->attach($source->id);
    $this->actingAs($editor)->get(route('management.sources.show', [$family->slug, $source->id]))->assertSee('المرفق السابق ليس في مخزن الملفات الخاصة')->assertDontSee('تنزيل الملف الخاص')->assertDontSee($source->file_path);
    $this->get(route('management.people.show', [$family->slug, $person->id]))->assertSee('مجموعة اصطناعية قديمة');
    $this->put(route('management.sources.update', [$family->slug, $source->id]), ['source_type' => 'genealogy_dataset', 'title' => 'عنوان محدث', 'reference' => 'LEGACY', 'verification_status' => 'sourced'])->assertSessionHasNoErrors();
    expect($source->fresh()->file_path)->toBe('docs/synthetic-legacy.json');
});
