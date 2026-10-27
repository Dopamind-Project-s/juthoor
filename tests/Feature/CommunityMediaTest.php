<?php

use App\Models\ArchiveRecord;
use App\Models\AuditLog;
use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\Diwan;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\MediaAttachment;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use App\Services\Community\CommunityContentService;
use App\Services\Community\MediaStorage;
use App\Services\Genealogy\PersonMergeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** A 3×2 JPEG made with GD (its gd-jpeg comment segment is metadata too). */
const MEDIA_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAgADAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4yiiig+OP//Z';

/** JPEG with an EXIF segment carrying a marker that must never reach viewers; $salt makes the checksum unique. */
function mediaJpeg(string $salt = ''): string
{
    $bytes = base64_decode(MEDIA_JPEG);
    $exif = "Exif\0\0SECRET-GPS-32.55,35.85".$salt;

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
}

/** 2×2 RGB PNG with a text chunk carrying the same kind of marker. */
function mediaPng(string $salt = ''): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $pixels = gzcompress("\0".str_repeat("\x1e\x78\x5a", 2)."\0".str_repeat("\x1e\x78\x5a", 2));

    return "\x89PNG\r\n\x1A\n".$chunk('IHDR', pack('NNCCCCC', 2, 2, 8, 2, 0, 0, 0)).$chunk('tEXt', "Comment\0SECRET-AUTHOR".$salt).$chunk('IDAT', $pixels).$chunk('IEND', '');
}

function mediaPdf(): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

/** @return array<string, mixed> */
function mediaForm(array $fields = [], array $governance = []): array
{
    return ['title' => 'صورة الديوان القديم', 'alt_text' => 'واجهة ديوان حجري', 'rights_status' => 'owned', ...$fields,
        'publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public', ...$governance];
}

beforeEach(function () {
    Storage::fake('media');
    $this->family = Family::query()->create(['name_ar' => 'عائلة زين', 'slug' => 'zain']);
    $this->tree = $this->family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $this->ancestor = $this->tree->people()->create(['first_name_ar' => 'قاسم', 'full_name_ar' => 'قاسم الزين', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $this->living = $this->tree->people()->create(['first_name_ar' => 'سالم', 'full_name_ar' => 'سالم الحي', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => true]);
    $entity = fn (string $slug, string $type, ?CommunityEntity $parent, string $status = 'published') => CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $parent?->id, 'entity_type' => $type, 'name_ar' => 'كيان '.$slug, 'slug' => $slug, 'publication_status' => $status]);
    $this->clan = $entity('clan', 'clan', null);
    $this->fakhd = $entity('f', 'fakhd', $this->clan);
    $this->sub = $entity('s', 'sub_fakhd', $this->fakhd);
    $this->sibling = $entity('g', 'fakhd', $this->clan);
    $this->draft = $entity('d', 'fakhd', $this->clan, 'draft');
    foreach (['admin', 'editor', 'reader'] as $role) {
        $this->{$role} = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $this->{$role}->id, 'role' => $role]);
        $this->{$role} = $this->{$role}->fresh();
    }
    $this->outsider = User::factory()->create()->fresh();
});

/** Uploads through the admin form and returns the stored item. */
function mediaUpload(object $test, User $user, CommunityEntity $entity, string $name, string $bytes, array $fields = [], array $governance = []): MediaItem
{
    $test->actingAs($user)->post(route('management.entities.content.store', [$entity->family->slug, $entity->id, 'media']), [...mediaForm($fields, $governance), 'file' => UploadedFile::fake()->createWithContent($name, $bytes)])
        ->assertRedirect()->assertSessionHasNoErrors();

    return MediaItem::query()->latest('id')->firstOrFail();
}

function mediaPublish(MediaItem $media, array $changes = []): MediaItem
{
    $media->forceFill(['publication_status' => 'published', 'reviewed_at' => now(), ...$changes])->save();

    return $media->fresh();
}

test('uploads are typed by content, stored under generated names and stripped of metadata for viewers', function () {
    $media = mediaUpload($this, $this->editor, $this->fakhd, '../../etc/صورة الديوان.jpg', mediaJpeg());
    $disk = Storage::disk('media');

    expect($media->category)->toBe('image')->and($media->mime_type)->toBe('image/jpeg')->and([$media->width, $media->height])->toBe([3, 2])
        ->and($media->original_name)->toBe('صورة الديوان.jpg')
        ->and($media->path)->toMatch('#^'.$this->family->id.'/[0-9a-f]{2}/[0-9a-f-]{36}\.jpg$#')->not->toContain('etc')
        ->and($media->checksum)->toBe(hash('sha256', mediaJpeg()))
        ->and($disk->get($media->path))->toContain('SECRET-GPS')
        ->and($disk->get($media->display_path))->not->toContain('SECRET-GPS')->not->toContain('CREATOR')
        ->and(getimagesizefromstring($disk->get($media->display_path))[0])->toBe(3);
    $png = mediaUpload($this, $this->editor, $this->fakhd, 'chart.png', mediaPng());
    expect($disk->get($png->display_path))->not->toContain('SECRET-AUTHOR')->and(getimagesizefromstring($disk->get($png->display_path))[0])->toBe(2);
    $pdf = mediaUpload($this, $this->editor, $this->fakhd, 'وثيقة.pdf', mediaPdf(), ['title' => 'وثيقة']);
    expect($pdf->category)->toBe('document')->and($pdf->display_path)->toBeNull();
});

test('unsafe, mislabelled, oversized and duplicate files are refused without leaving files behind', function () {
    $this->actingAs($this->admin);
    $store = fn (string $name, string $bytes) => $this->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'media']), [...mediaForm(), 'file' => UploadedFile::fake()->createWithContent($name, $bytes)]);

    $store('page.jpg', '<html><script>alert(1)</script></html>')->assertSessionHasErrors('file');
    $store('shell.png', "<?php system(\$_GET['c']); ?>")->assertSessionHasErrors('file');
    $store('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')->assertSessionHasErrors('file');
    $store('photo.png', mediaJpeg())->assertSessionHasErrors('file');
    $store('archive.zip', "PK\x03\x04".str_repeat('x', 40))->assertSessionHasErrors('file');
    $store('polyglot.jpg', mediaJpeg().'<script>alert(1)</script>')->assertSessionHasErrors('file');
    config(['media.max_kb.image' => 0]);
    $store('big.jpg', mediaJpeg('big'))->assertSessionHasErrors('file');
    config(['media.max_kb.image' => 10240, 'media.max_pixels_side' => 2]);
    $store('wide.jpg', mediaJpeg('wide'))->assertSessionHasErrors('file');
    config(['media.max_pixels_side' => 12000]);

    $store('one.jpg', mediaJpeg('dup'))->assertSessionHasNoErrors();
    $store('same-again.jpg', mediaJpeg('dup'))->assertSessionHasErrors('file');
    // A write refused after the file was stored (link outside the uploader's scope) removes the stored files.
    CommunityEntityManager::query()->create(['community_entity_id' => $this->fakhd->id, 'user_id' => $this->reader->id, 'role' => 'editor']);
    $this->actingAs($this->reader)->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'media']), [...mediaForm([], ['subjects' => [$this->sibling->id]]), 'file' => UploadedFile::fake()->createWithContent('scope.jpg', mediaJpeg('scope'))])
        ->assertSessionHasErrors('subjects');

    $rows = MediaItem::query()->get();
    expect($rows->pluck('original_name')->sort()->values()->all())->toBe(['one.jpg'])
        ->and(collect(Storage::disk('media')->allFiles())->sort()->values()->all())->toBe($rows->flatMap(fn ($m) => [$m->path, $m->display_path])->sort()->values()->all());
});

test('the media route enforces publication, audience and roles and never reveals originals to viewers', function () {
    $media = mediaPublish(mediaUpload($this, $this->admin, $this->fakhd, 'diwan.jpg', mediaJpeg()));
    auth()->logout();

    $response = $this->get($media->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/jpeg');
    expect($response->headers->get('Content-Security-Policy'))->toContain('sandbox')->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->streamedContent())->not->toContain('SECRET-GPS');
    $this->get($media->url('original'))->assertNotFound();
    $this->get(route('media.show', ['00000000-0000-4000-8000-000000000000', 'display']))->assertNotFound();
    $this->actingAs($this->editor)->get($media->url('original'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($this->get($media->url('original'))->streamedContent())->toContain('SECRET-GPS');

    $media->update(['audience' => 'members']);
    auth()->logout();
    $this->get($media->url())->assertNotFound();
    $this->actingAs($this->outsider)->get($media->url())->assertNotFound();
    $this->actingAs($this->reader)->get($media->url())->assertOk();

    $media->update(['audience' => 'restricted']);
    $this->get($media->url())->assertNotFound();
    $this->actingAs($this->editor)->get($media->url('original'))->assertNotFound();
    $this->actingAs($this->admin)->get($media->url())->assertOk();
    $this->get($media->url('original'))->assertOk();

    $media->update(['audience' => 'public', 'publication_status' => 'draft']);
    $this->actingAs($this->reader)->get($media->url())->assertNotFound();
    $this->actingAs($this->editor)->get($media->url())->assertOk();

    // Public files of an entity whose page is not public stay hidden.
    $hidden = mediaPublish(mediaUpload($this, $this->admin, $this->draft, 'hidden.jpg', mediaJpeg('hidden')));
    auth()->logout();
    $this->get($hidden->url())->assertNotFound();
});

test('usage rights, alternative text and audience are checked whenever a file or archive entry is published', function () {
    $this->actingAs($this->admin);
    $store = fn (array $fields, array $governance, string $salt) => $this->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'media']), [...mediaForm($fields, $governance), 'file' => UploadedFile::fake()->createWithContent("{$salt}.jpg", mediaJpeg($salt))]);

    $store(['rights_status' => 'unknown'], ['publication_status' => 'published'], 'a')->assertSessionHasErrors('rights_status');
    $store(['rights_status' => 'unknown'], ['publication_status' => 'published', 'audience' => 'members'], 'b')->assertSessionHasNoErrors();
    $store(['rights_status' => 'restricted'], ['publication_status' => 'published', 'audience' => 'members'], 'c')->assertSessionHasErrors('rights_status');
    $store(['alt_text' => ''], ['publication_status' => 'published'], 'd')->assertSessionHasErrors('alt_text');
    $members = MediaItem::query()->where('rights_status', 'unknown')->sole();
    $this->put(route('management.entities.content.update', ['zain', $this->fakhd->id, 'media', $members->id]), mediaForm(['rights_status' => 'unknown'], ['publication_status' => 'published']))->assertSessionHasErrors('rights_status');
    expect($members->fresh()->audience)->toBe('members');

    $archive = route('management.entities.content.store', ['zain', $this->fakhd->id, 'archive']);
    $this->post($archive, ['title' => 'مخطوطة نسب', 'category' => 'manuscript', 'rights_status' => 'unknown', 'publication_status' => 'published', 'verification_status' => 'pending_review', 'audience' => 'public'])->assertSessionHasErrors('rights_status');
    $this->post($archive, ['title' => 'مخطوطة نسب', 'category' => 'manuscript', 'rights_status' => 'permission', 'rights_holder' => 'مكتبة العائلة', 'publication_status' => 'published', 'verification_status' => 'pending_review', 'audience' => 'public'])->assertSessionHasNoErrors();
    // Uploading heritage material never verifies it.
    expect(ArchiveRecord::query()->sole()->verification_status)->toBe('pending_review');
});

test('an archive entry with attached files is shared by several entities without copying the files', function () {
    $image = mediaPublish(mediaUpload($this, $this->admin, $this->fakhd, 'chart.jpg', mediaJpeg('chart')));
    $draftFile = mediaUpload($this, $this->admin, $this->fakhd, 'draft.pdf', mediaPdf(), ['title' => 'مسودة وثيقة']);
    $this->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'archive']), [
        'title' => 'مشجرة آل زين', 'category' => 'genealogy_chart', 'original_source' => 'مكتبة خاصة', 'contributor' => 'أبو قاسم', 'person_id' => $this->ancestor->id,
        'rights_status' => 'owned', 'publication_status' => 'published', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [$this->fakhd->id, $this->sibling->id],
    ])->assertSessionHasNoErrors();
    $record = ArchiveRecord::query()->sole();
    foreach ([$image, $draftFile] as $media) {
        $this->post(route('management.entities.content.media.store', ['zain', $this->fakhd->id, 'archive', $record->id]), ['media_item_id' => $media->id, 'caption' => 'نسخة مصورة'])->assertSessionHasNoErrors();
    }
    auth()->logout();

    foreach (['f', 'g'] as $slug) {
        $this->get(route('community.entities.show', ['zain', $slug]))->assertOk()->assertSee('مشجرة آل زين')->assertSee('مشجرة نسب تاريخية')
            ->assertSee($image->url(), false)->assertDontSee($draftFile->url(), false)->assertDontSee($image->path, false)->assertSee('مسجل دون تحقق');
    }
    expect(substr_count($this->get(route('community.entities.show', ['zain', 'clan']))->getContent(), 'data-record="archive-'.$record->id.'"'))->toBe(1)
        ->and(MediaItem::query()->count())->toBe(2)->and(count(Storage::disk('media')->allFiles()))->toBe(3);

    // An entry about a person who is not public is hidden from visitors only.
    $record->update(['person_id' => $this->living->id]);
    $this->get(route('community.entities.show', ['zain', 'f']))->assertDontSee('مشجرة آل زين');
    $this->actingAs($this->reader)->get(route('community.entities.show', ['zain', 'f']))->assertSee('مشجرة آل زين');
});

test('diwans take images only, and detaching keeps the library file', function () {
    $diwan = app(CommunityContentService::class)->save($this->admin, $this->fakhd, new Diwan, ['name' => 'ديوان آل زين', 'kind' => 'diwan'], ['publication_status' => 'published', 'verification_status' => 'sourced', 'audience' => 'public', 'subjects' => [], 'audiences' => []]);
    $photo = mediaPublish(mediaUpload($this, $this->admin, $this->fakhd, 'diwan.jpg', mediaJpeg('d')));
    $pdf = mediaUpload($this, $this->admin, $this->fakhd, 'deed.pdf', mediaPdf(), ['title' => 'صك']);
    $attach = route('management.entities.content.media.store', ['zain', $this->fakhd->id, 'diwans', $diwan->id]);

    $this->post($attach, ['media_item_id' => $pdf->id])->assertSessionHasErrors('media_item_id');
    $this->post($attach, ['media_item_id' => $photo->id])->assertSessionHasNoErrors();
    auth()->logout();
    $this->get(route('community.entities.show', ['zain', 'f']))->assertSee('data-media="'.$photo->uuid.'"', false)->assertSee('واجهة ديوان حجري');

    // Editors cannot change the files of a published diwan.
    $attachment = $diwan->mediaAttachments()->sole();
    $this->actingAs($this->editor)->delete(route('management.entities.content.media.destroy', ['zain', $this->fakhd->id, 'diwans', $diwan->id, $attachment->id]))->assertForbidden();
    $this->actingAs($this->admin)->delete(route('management.entities.content.media.destroy', ['zain', $this->fakhd->id, 'diwans', $diwan->id, $attachment->id]))->assertSessionHasNoErrors();
    expect(MediaItem::query()->whereKey($photo->id)->exists())->toBeTrue()->and(Storage::disk('media')->exists($photo->path))->toBeTrue();
});

test('entity covers must be family images and appear only while published for everyone', function () {
    $cover = mediaUpload($this, $this->admin, $this->fakhd, 'cover.jpg', mediaJpeg('cover'));
    $pdf = mediaUpload($this, $this->admin, $this->fakhd, 'doc.pdf', mediaPdf(), ['title' => 'وثيقة']);
    $input = ['name_ar' => 'كيان f', 'slug' => 'f', 'entity_type' => 'fakhd', 'parent_id' => $this->clan->id, 'publication_status' => 'published'];

    $this->put(route('management.entities.update', ['zain', $this->fakhd->id]), [...$input, 'cover_media_id' => $pdf->id])->assertSessionHasErrors('cover_media_id');
    $this->put(route('management.entities.update', ['zain', $this->fakhd->id]), [...$input, 'cover_media_id' => $cover->id])->assertSessionHasNoErrors();
    auth()->logout();
    $this->get(route('community.entities.show', ['zain', 'f']))->assertDontSee('data-entity-cover', false);
    mediaPublish($cover);
    $this->get(route('community.entities.show', ['zain', 'f']))->assertSee('data-entity-cover', false)->assertSee($cover->url(), false);
    $cover->update(['audience' => 'members']);
    $this->get(route('community.entities.show', ['zain', 'f']))->assertDontSee('data-entity-cover', false);
});

test('files in use cannot be deleted; unused drafts are deleted with their files, replacements swap files safely', function () {
    $used = mediaUpload($this, $this->admin, $this->fakhd, 'used.jpg', mediaJpeg('used'));
    $record = app(CommunityContentService::class)->save($this->admin, $this->fakhd, new ArchiveRecord, ['title' => 'صورة قديمة', 'category' => 'photograph', 'rights_status' => 'owned'], ['publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [], 'audiences' => []]);
    $this->post(route('management.entities.content.media.store', ['zain', $this->fakhd->id, 'archive', $record->id]), ['media_item_id' => $used->id]);
    $destroy = fn (MediaItem $media) => $this->delete(route('management.entities.content.destroy', ['zain', $this->fakhd->id, 'media', $media->id]));

    $destroy($used)->assertSessionHasErrors('media');
    // The foreign key refuses it too; the savepoint keeps PostgreSQL's test transaction usable.
    expect(fn () => DB::transaction(fn () => $used->delete()))->toThrow(QueryException::class);
    $published = mediaPublish(mediaUpload($this, $this->admin, $this->fakhd, 'pub.jpg', mediaJpeg('pub')));
    $destroy($published)->assertSessionHasErrors('media');

    $unused = mediaUpload($this, $this->admin, $this->fakhd, 'unused.jpg', mediaJpeg('unused'));
    $files = [$unused->path, $unused->display_path];
    $destroy($unused)->assertRedirect();
    expect(MediaItem::query()->whereKey($unused->id)->exists())->toBeFalse()->and(Storage::disk('media')->exists($files[0]))->toBeFalse()->and(Storage::disk('media')->exists($files[1]))->toBeFalse();

    $old = [$used->path, $used->display_path];
    $update = route('management.entities.content.update', ['zain', $this->fakhd->id, 'media', $used->id]);
    $this->put($update, [...mediaForm(), 'file' => UploadedFile::fake()->createWithContent('doc.pdf', mediaPdf())])->assertSessionHasErrors('file');
    $this->put($update, [...mediaForm(), 'file' => UploadedFile::fake()->createWithContent('copy.jpg', mediaJpeg('pub'))])->assertSessionHasErrors('file');
    expect(Storage::disk('media')->exists($old[0]))->toBeTrue();
    $this->put($update, [...mediaForm(), 'file' => UploadedFile::fake()->createWithContent('better.jpg', mediaJpeg('better'))])->assertSessionHasNoErrors();
    $used->refresh();
    expect($used->checksum)->toBe(hash('sha256', mediaJpeg('better')))->and($used->original_name)->toBe('better.jpg')
        ->and(Storage::disk('media')->exists($old[0]))->toBeFalse()->and(Storage::disk('media')->exists($used->path))->toBeTrue()
        ->and($record->mediaAttachments()->sole()->media_item_id)->toBe($used->id)
        ->and(AuditLog::query()->where('auditable_type', MediaItem::class)->where('auditable_id', $used->id)->orderBy('id')->pluck('action')->all())->toBe(['created', 'file_replaced', 'updated'])
        ->and(AuditLog::query()->where('auditable_type', MediaAttachment::class)->where('action', 'created')->exists())->toBeTrue();
});

test('the gallery follows the shared scope and sibling isolation', function () {
    $subPhoto = mediaPublish(mediaUpload($this, $this->admin, $this->sub, 'sub.jpg', mediaJpeg('sub')));
    $siblingPhoto = mediaPublish(mediaUpload($this, $this->admin, $this->sibling, 'sib.jpg', mediaJpeg('sib')));
    auth()->logout();
    $records = fn (string $slug, ?string $scope = null) => (preg_match_all('/data-record="(media-\d+)"/', $this->get(route('community.entities.show', ['zain', $slug, 'scope' => $scope]))->getContent(), $m) ? $m[1] : []);

    expect($records('clan'))->toContain("media-{$subPhoto->id}", "media-{$siblingPhoto->id}")
        ->and($records('clan', 'direct'))->toBe([])
        ->and($records('f'))->toBe(["media-{$subPhoto->id}"])
        ->and($records('g'))->toBe(["media-{$siblingPhoto->id}"]);
});

test('uploads follow entity scope and family isolation', function () {
    $other = Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other']);
    $otherAdmin = User::factory()->create();
    FamilyMembership::query()->create(['family_id' => $other->id, 'user_id' => $otherAdmin->id, 'role' => 'admin']);
    $otherEntity = CommunityEntity::query()->create(['family_id' => $other->id, 'entity_type' => 'clan', 'name_ar' => 'x', 'slug' => 'x', 'publication_status' => 'published']);
    $foreign = mediaUpload($this, $otherAdmin, $otherEntity, 'foreign.jpg', mediaJpeg('foreign'));
    $mine = app(CommunityContentService::class)->save($this->admin, $this->fakhd, new ArchiveRecord, ['title' => 'و', 'category' => 'document', 'rights_status' => 'owned'], ['publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [], 'audiences' => []]);

    $this->actingAs($this->admin)->post(route('management.entities.content.media.store', ['zain', $this->fakhd->id, 'archive', $mine->id]), ['media_item_id' => $foreign->id])->assertSessionHasErrors('media_item_id');
    $this->actingAs($this->reader)->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'media']), [...mediaForm(), 'file' => UploadedFile::fake()->createWithContent('r.jpg', mediaJpeg('r'))])->assertForbidden();
    $this->actingAs($otherAdmin)->get(route('management.entities.content.index', ['zain', $this->fakhd->id, 'media']))->assertForbidden();
    $this->actingAs($this->editor)->post(route('management.entities.content.store', ['zain', $this->fakhd->id, 'media']), [...mediaForm([], ['publication_status' => 'published']), 'file' => UploadedFile::fake()->createWithContent('e.jpg', mediaJpeg('e'))])->assertSessionHasErrors('publication_status');
    expect(MediaItem::query()->where('family_id', $this->family->id)->count())->toBe(0)->and(Storage::disk('media')->allFiles())->toHaveCount(2);
});

test('people referenced by archive entries cannot be deleted or merged away', function () {
    $other = $this->tree->people()->create(['first_name_ar' => 'قاسم', 'full_name_ar' => 'قاسم الزين', 'status' => 'historical', 'verification_status' => 'pending_review', 'is_living' => false]);
    app(CommunityContentService::class)->save($this->admin, $this->fakhd, new ArchiveRecord, ['title' => 'رسالة', 'category' => 'document', 'rights_status' => 'owned', 'person_id' => $other->id], ['publication_status' => 'draft', 'verification_status' => 'pending_review', 'audience' => 'public', 'subjects' => [], 'audiences' => []]);

    $this->actingAs($this->admin)->delete(route('management.people.destroy', ['zain', $other->id]))->assertSessionHasErrors('person');
    expect(Person::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(app(PersonMergeService::class)->plan($this->tree, $this->ancestor->id, $other->id)['blockers'])->toContain('السجل المدموج مرتبط بمواد أرشيفية؛ اربطها بالسجل الباقي من صفحات الكيانات أولًا.');
});

test('media:verify reports missing files, checksum mismatches and orphans', function () {
    $media = mediaUpload($this, $this->admin, $this->fakhd, 'v.jpg', mediaJpeg('v'));
    $this->artisan('media:verify')->assertSuccessful();

    Storage::disk('media')->put('orphan/left-over.jpg', 'x');
    Storage::disk('media')->put($media->path, 'tampered');
    $this->artisan('media:verify')->expectsOutputToContain('بصمة غير مطابقة')->expectsOutputToContain('ملف بلا سجل')->assertFailed();
    $this->artisan('media:verify --delete-orphans')->assertFailed();
    expect(Storage::disk('media')->exists('orphan/left-over.jpg'))->toBeFalse()->and(Storage::disk('media')->exists($media->path))->toBeTrue();
});

test('the JPEG and PNG metadata strippers keep the image decodable', function () {
    $storage = app(MediaStorage::class);
    $jpeg = $storage->stripJpeg(mediaJpeg());
    $png = $storage->stripPng(mediaPng());

    expect($jpeg)->not->toContain('SECRET')->and(getimagesizefromstring($jpeg)[0])->toBe(3)
        ->and($png)->not->toContain('SECRET')->and(getimagesizefromstring($png)[0])->toBe(2)
        ->and($storage->safeName("..\\..\\a/b/\x00evil.php.jpg"))->toBe('evil.php.jpg');
});
