<?php

use App\Models\CommunityEntity;
use App\Models\DirectoryReport;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\MemberProfile;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media');
    $this->family = Family::query()->create(['name_ar' => 'عائلة زين', 'slug' => 'zain']);
    $this->tree = $this->family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $this->person = $this->tree->people()->create(['first_name_ar' => 'محمد', 'full_name_ar' => 'محمد الزين', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => true]);
    $entity = fn (string $slug, string $type, ?CommunityEntity $parent) => CommunityEntity::query()->create(['family_id' => $this->family->id, 'parent_id' => $parent?->id, 'entity_type' => $type, 'name_ar' => 'كيان '.$slug, 'slug' => $slug, 'publication_status' => 'published']);
    $this->clan = $entity('clan', 'clan', null);
    $this->fakhd = $entity('f', 'fakhd', $this->clan);
    $this->sub = $entity('s', 'sub_fakhd', $this->fakhd);
    $this->sibling = $entity('g', 'fakhd', $this->clan);
    foreach (['admin', 'member', 'other'] as $name) {
        $user = User::factory()->create(['name' => 'مستخدم '.$name]);
        FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $user->id, 'role' => $name === 'admin' ? 'admin' : 'reader']);
        $this->{$name} = $user->fresh();
    }
});

/** @return array<string, mixed> */
function directoryForm(array $fields = [], array $visibility = []): array
{
    return ['display_name' => 'م. محمد الزين', 'profession' => 'مهندس مدني', 'specialization' => 'جسور', 'sector' => 'engineering', 'skills' => 'تصميم إنشائي، إدارة مشاريع',
        'organization' => 'شركة الإنشاءات الخاصة', 'city' => 'إربد', 'governorate' => 'إربد', 'contact_email' => 'private@example.com', 'contact_phone' => '+962 7 1234 5678',
        'audience' => 'members', 'is_listed' => 1, 'visibility' => $visibility, ...$fields];
}

function saveProfile(object $test, User $user, array $fields = [], array $visibility = []): MemberProfile
{
    $test->actingAs($user)->put(route('management.directory.update', 'zain'), directoryForm($fields, $visibility))->assertSessionHasNoErrors();

    return MemberProfile::query()->where('user_id', $user->id)->sole();
}

test('profiles are opt-in and never created from genealogy', function () {
    $this->get(route('directory.index', 'zain'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect(MemberProfile::query()->count())->toBe(0);

    $profile = saveProfile($this, $this->member, ['is_listed' => 0]);
    expect($profile->is_listed)->toBeFalse();
    $this->actingAs($this->other)->get(route('directory.index', 'zain'))->assertDontSee('م. محمد الزين');
    $this->get(route('directory.show', ['zain', $profile->id]))->assertNotFound();
    $this->actingAs($this->member)->get(route('directory.show', ['zain', $profile->id]))->assertOk()->assertSee('تراه كاملًا');

    saveProfile($this, $this->member);
    $this->actingAs($this->other)->get(route('directory.index', 'zain'))->assertSee('م. محمد الزين');
    expect(Person::query()->count())->toBe(1);
});

test('hidden and member-only fields never reach other audiences, in pages or search', function () {
    $profile = saveProfile($this, $this->member);

    // Defaults: workplace and contact details hidden from everyone but the owner.
    $page = $this->actingAs($this->other)->get(route('directory.show', ['zain', $profile->id]))->assertOk()
        ->assertSee('مهندس مدني')->assertSee('إربد')->assertDontSee('private@example.com')->assertDontSee('1234 5678')->assertDontSee('شركة الإنشاءات الخاصة');
    $search = fn (array $query) => $this->get(route('directory.index', ['zain', ...$query]))->getContent();
    expect($search(['q' => 'جسور']))->toContain('data-profile="'.$profile->id.'"')
        ->and($search(['q' => 'شركة الإنشاءات']))->not->toContain('data-profile="'.$profile->id.'"')
        ->and($search(['q' => 'private@example']))->not->toContain('data-profile="'.$profile->id.'"')
        ->and($search(['skill' => 'تصميم']))->toContain('data-profile="'.$profile->id.'"')
        ->and($search(['city' => 'إربد']))->toContain('data-profile="'.$profile->id.'"');

    // A members-only profile is invisible to visitors; a public profile still caps each field at its own level.
    auth()->logout();
    $this->get(route('directory.show', ['zain', $profile->id]))->assertNotFound();
    expect($search([]))->not->toContain('data-profile="'.$profile->id.'"');
    saveProfile($this, $this->member, ['audience' => 'public'], ['profession' => 'public', 'city' => 'members', 'contact_phone' => 'public']);
    auth()->logout();
    $this->get(route('directory.show', ['zain', $profile->id]))->assertOk()->assertSee('مهندس مدني')->assertSee('1234 5678')->assertDontSee('data-field="city"', false)->assertDontSee('private@example.com');
    expect($search(['city' => 'إربد']))->not->toContain('data-profile="'.$profile->id.'"')
        ->and($search(['q' => 'مهندس']))->toContain('data-profile="'.$profile->id.'"')
        // Skills stay at the members level, so visitors' free-text search cannot match them either.
        ->and($search(['q' => 'تصميم إنشائي']))->not->toContain('data-profile="'.$profile->id.'"')
        ->and($this->get(route('directory.index', 'zain'))->getContent())->not->toContain('<option>إربد</option>');
    // Members never see more than the field's own level allows.
    saveProfile($this, $this->member, ['audience' => 'members'], ['contact_phone' => 'public']);
    $this->actingAs($this->other)->get(route('directory.show', ['zain', $profile->id]))->assertSee('1234 5678');
    auth()->logout();
    $this->get(route('directory.show', ['zain', $profile->id]))->assertNotFound();
});

test('affiliation is shown and filterable only after a family administrator verifies it, along the hierarchy', function () {
    $profile = saveProfile($this, $this->member, ['entity_id' => $this->sub->id]);
    expect($profile->affiliation_status)->toBe('pending')->and($this->admin->fresh()->notifications()->count())->toBe(1);
    $filter = fn (int $entity) => $this->actingAs($this->other)->get(route('directory.index', ['zain', 'entity' => $entity]))->getContent();

    expect($filter($this->fakhd->id))->not->toContain('data-profile="'.$profile->id.'"');
    $this->actingAs($this->admin)->get(route('management.directory.review', 'zain'))->assertOk()->assertSee('م. محمد الزين')->assertDontSee('private@example.com')->assertDontSee('شركة الإنشاءات الخاصة');
    $this->post(route('management.directory.affiliation', ['zain', $profile->id]), ['decision' => 'verified'])->assertSessionHasNoErrors();

    expect($filter($this->fakhd->id))->toContain('data-profile="'.$profile->id.'"')
        ->and($filter($this->sub->id))->toContain('data-profile="'.$profile->id.'"')
        ->and($filter($this->sibling->id))->not->toContain('data-profile="'.$profile->id.'"')
        ->and($this->member->fresh()->notifications->contains(fn ($n) => $n->data['kind'] === 'directory_affiliation_verified'))->toBeTrue();
    // Changing the claim sends it back to review.
    expect(saveProfile($this, $this->member, ['entity_id' => $this->sibling->id])->affiliation_status)->toBe('pending');
    $this->actingAs($this->other)->post(route('management.directory.affiliation', ['zain', $profile->id]), ['decision' => 'verified'])->assertForbidden();
});

test('contact requests and reports go through the platform without revealing addresses', function () {
    $profile = saveProfile($this, $this->member);
    $this->actingAs($this->other)->post(route('directory.contact', ['zain', $profile->id]), ['message' => 'أرغب بالتواصل بخصوص مشروع هندسي'])->assertSessionHasNoErrors();
    $notification = $this->member->fresh()->notifications()->sole();
    expect($notification->data['body'])->toBe('أرغب بالتواصل بخصوص مشروع هندسي')->and(json_encode($notification->data, JSON_UNESCAPED_UNICODE))->not->toContain($this->other->email);

    saveProfile($this, $this->member, [], ['messages' => 'hidden']);
    $this->actingAs($this->other)->post(route('directory.contact', ['zain', $profile->id]), ['message' => 'رسالة ثانية للتجربة'])->assertForbidden();
    $this->get(route('directory.show', ['zain', $profile->id]))->assertDontSee('data-contact-form', false);

    $this->post(route('directory.report', ['zain', $profile->id]), ['reason' => 'incorrect', 'details' => 'التخصص غير دقيق'])->assertSessionHasNoErrors();
    $report = DirectoryReport::query()->sole();
    expect($this->admin->fresh()->notifications->contains(fn ($n) => $n->data['kind'] === 'directory_report'))->toBeTrue();
    $this->actingAs($this->admin)->post(route('management.directory.resolve', ['zain', $report->id]), ['action' => 'suspend', 'note' => 'قيد التحقق'])->assertSessionHasNoErrors();
    $this->actingAs($this->other)->get(route('directory.show', ['zain', $profile->id]))->assertNotFound();
    expect($profile->fresh()->profession)->toBe('مهندس مدني');
    $this->actingAs($this->admin)->post(route('management.directory.reinstate', ['zain', $profile->id]))->assertSessionHasNoErrors();
    $this->actingAs($this->other)->get(route('directory.show', ['zain', $profile->id]))->assertOk();
});

test('members delete their profile without touching their account or the genealogy', function () {
    $profile = saveProfile($this, $this->member);
    $this->actingAs($this->member)->delete(route('management.directory.destroy', 'zain'))->assertRedirect();

    expect(MemberProfile::query()->whereKey($profile->id)->exists())->toBeFalse()->and(User::query()->whereKey($this->member->id)->exists())->toBeTrue()
        ->and(Person::query()->whereKey($this->person->id)->exists())->toBeTrue();
});

test('profile photos are metadata-free and shown only where the photo field is visible', function () {
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAgADAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4yiiig+OP//Z');
    $exif = "Exif\0\0SECRET-HOME";
    $photo = UploadedFile::fake()->createWithContent('me.jpg', "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
    $this->actingAs($this->member)->put(route('management.directory.update', 'zain'), [...directoryForm(), 'photo' => $photo])->assertSessionHasNoErrors();
    $profile = MemberProfile::query()->sole();

    expect(Storage::disk('media')->get($profile->photo_path))->not->toContain('SECRET-HOME')->and(Storage::disk('media')->allFiles())->toHaveCount(1);
    $this->actingAs($this->other)->get(route('directory.photo', ['zain', $profile->id]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    auth()->logout();
    $this->get(route('directory.photo', ['zain', $profile->id]))->assertNotFound();
    saveProfile($this, $this->member, [], ['photo' => 'hidden']);
    $this->actingAs($this->other)->get(route('directory.photo', ['zain', $profile->id]))->assertNotFound();
    $this->actingAs($this->member)->put(route('management.directory.update', 'zain'), [...directoryForm(), 'photo' => UploadedFile::fake()->createWithContent('x.jpg', '<html>x</html>')])->assertSessionHasErrors('file');
});

test('only verified family members have profiles, per family', function () {
    $outsider = User::factory()->create()->fresh();
    $this->actingAs($outsider)->get(route('management.directory.edit', 'zain'))->assertForbidden();
    $this->actingAs($outsider)->put(route('management.directory.update', 'zain'), directoryForm())->assertForbidden();
    $unverified = User::factory()->unverified()->create();
    FamilyMembership::query()->create(['family_id' => $this->family->id, 'user_id' => $unverified->id, 'role' => 'reader']);
    $this->actingAs($unverified->fresh())->get(route('management.directory.edit', 'zain'))->assertRedirect(route('verification.notice'));

    $profile = saveProfile($this, $this->member);
    $other = Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other']);
    $this->actingAs($this->other)->get(route('directory.show', ['other', $profile->id]))->assertNotFound();
    $this->actingAs($this->member)->put(route('management.directory.update', 'zain'), directoryForm(['entity_id' => CommunityEntity::query()->create(['family_id' => $other->id, 'entity_type' => 'clan', 'name_ar' => 'x', 'slug' => 'x', 'publication_status' => 'published'])->id]))->assertSessionHasErrors('entity_id');
    $this->actingAs($this->member)->put(route('management.directory.update', 'zain'), directoryForm(['links' => 'javascript:alert(1)']))->assertSessionHasErrors('links');
});
