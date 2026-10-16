<?php

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\User;
use App\Services\AuditReversionService;
use Database\Seeders\PersonAttributeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function descriptorContext(): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة الصفات', 'slug' => 'descriptors']);
    $tree = $family->familyTrees()->create(['name_ar' => 'الرئيسية', 'slug' => 'main', 'is_primary' => true]);
    $super = User::factory()->create();
    $super->forceFill(['is_super_admin' => true])->save();
    $admin = User::factory()->create();
    $family->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
    app(PersonAttributeSeeder::class)->run();

    return [$family, $tree, $super, $admin];
}

function descriptorPersonData(array $extra = []): array
{
    return array_replace(['first_name_ar' => 'شخص', 'full_name_ar' => 'شخص اختباري', 'status' => 'historical', 'verification_status' => 'verified'], $extra);
}

test('reference attributes seed idempotently without resetting administrator customization', function () {
    app(PersonAttributeSeeder::class)->run();
    $attribute = PersonAttribute::query()->where('key', 'prophet')->sole();
    $attribute->update(['label' => 'اسم مخصص', 'color' => '#112233', 'is_active' => false]);
    app(PersonAttributeSeeder::class)->run();
    expect(PersonAttribute::query()->count())->toBe(6)
        ->and($attribute->fresh()->label)->toBe('اسم مخصص')
        ->and($attribute->fresh()->color)->toBe('#112233')
        ->and($attribute->fresh()->is_active)->toBeFalse();
});

test('super admins manage shared attributes and family admins manage only their own definitions', function () {
    [$family, $tree, $super, $admin] = descriptorContext();
    $this->actingAs($super)->get(route('site-admin.attributes.index'))->assertOk()->assertSee('نبي');
    $this->get(route('site-admin.attributes.create'))->assertOk();
    $this->post(route('site-admin.attributes.store'), ['label' => 'باحث', 'color' => '#ABC123', 'is_active' => 1])->assertSessionHasNoErrors();
    $global = PersonAttribute::query()->where('label', 'باحث')->sole();
    $this->get(route('site-admin.attributes.edit', $global->id))->assertOk();
    $this->put(route('site-admin.attributes.update', $global->id), ['label' => 'باحث محدث', 'color' => '#123456', 'is_active' => 0])->assertSessionHasNoErrors();
    expect($global->fresh()->is_active)->toBeFalse();
    $this->actingAs($admin)->get(route('management.attributes.index', $family->slug))->assertOk()->assertSee('نبي');
    $this->get(route('management.attributes.create', $family->slug))->assertOk();
    $this->post(route('management.attributes.store', $family->slug), ['label' => 'صفة محلية', 'color' => '#010203', 'is_active' => 1])->assertSessionHasNoErrors();
    $local = PersonAttribute::query()->where('label', 'صفة محلية')->sole();
    expect($local->family_id)->toBe($family->id);
    $this->get(route('management.attributes.edit', [$family->slug, $local->id]))->assertOk();
    $this->put(route('management.attributes.update', [$family->slug, $local->id]), ['label' => 'محلية محدثة', 'color' => '#FFFFFF', 'is_active' => 0])->assertSessionHasNoErrors();
    $this->get(route('site-admin.attributes.index'))->assertForbidden();
    $this->put(route('site-admin.attributes.update', $global->id), ['label' => 'مرفوض', 'color' => '#000000', 'is_active' => 1])->assertForbidden();
    $this->put(route('management.attributes.update', [$family->slug, $global->id]), ['label' => 'مرفوض', 'color' => '#000000', 'is_active' => 1])->assertNotFound();
    $other = Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other']);
    $this->get(route('management.attributes.index', $other->slug))->assertForbidden();
    foreach (['editor', 'reader'] as $role) {
        $user = User::factory()->create();
        $family->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        $this->actingAs($user)->get(route('management.attributes.index', $family->slug))->assertForbidden();
        $this->post(route('management.attributes.store', $family->slug), ['label' => 'مرفوض', 'color' => '#123456', 'is_active' => 1])->assertForbidden();
    }
    expect(AuditLog::query()->where('auditable_type', PersonAttribute::class)->count())->toBe(4);
});

test('invalid colors are rejected and assignment excludes foreign or newly inactive attributes', function () {
    [$family, $tree, $super, $admin] = descriptorContext();
    $this->actingAs($admin)->post(route('management.attributes.store', $family->slug), ['label' => 'غير صالح', 'color' => 'red;background:url(x)', 'is_active' => 1])->assertSessionHasErrors('color');
    $other = Family::query()->create(['name_ar' => 'أخرى', 'slug' => 'other']);
    $foreign = PersonAttribute::query()->create(['family_id' => $other->id, 'key' => 'foreign', 'label' => 'خاصة', 'color' => '#123456']);
    $inactive = PersonAttribute::query()->where('key', 'prophet')->sole();
    $inactive->update(['is_active' => false]);
    foreach ([$foreign, $inactive] as $attribute) {
        $this->post(route('management.people.store', $family->slug), descriptorPersonData(['attribute_ids' => [$attribute->id]]))->assertSessionHasErrors('attribute_ids');
    }
    expect($tree->people()->count())->toBe(0);
});

test('titles honorifics attributes colors and deactivation flow through profiles lists graph and cards', function () {
    [$family, $tree, $super, $admin] = descriptorContext();
    $attribute = PersonAttribute::query()->where('key', 'companion')->sole();
    $this->actingAs($admin)->post(route('management.people.store', $family->slug),
        descriptorPersonData(['title' => 'لقب اختباري', 'honorific' => 'نص تكريم', 'attribute_ids' => [$attribute->id], 'attributes_present' => 1]))
        ->assertSessionHasNoErrors();
    $person = $tree->people()->sole();
    $tree->update(['root_person_id' => $person->id]);
    expect($person->full_name_ar)->toBe('شخص اختباري')->and($person->display_name)->toBe('شخص اختباري — نص تكريم — لقب اختباري');
    $this->get(route('management.people.show', [$family->slug, $person->id]))->assertOk()->assertSee('لقب اختباري')->assertSee($attribute->color);
    $this->get(route('management.sections.index', [$family->slug, 'people']))->assertOk()->assertSee('لقب اختباري')->assertSee('صحابي');
    $this->get(route('genealogy.person', [$family->slug, $person->id]))->assertOk()->assertSee('لقب اختباري')->assertSee('صحابي');
    $graph = $this->getJson(route('genealogy.graph', $family->slug))->assertOk()->json();
    expect($graph['nodes'][0]['attributes'][0]['color'])->toBe($attribute->color)
        ->and($graph['nodes'][0]['title'])->toBe('لقب اختباري');
    $this->blade('<x-person-card :person="$person" />', ['person' => $person])->assertSee('لقب اختباري')->assertSee('صحابي');
    $attribute->update(['label' => 'وصف محدث', 'color' => '#EFEFEF']);
    $this->getJson(route('genealogy.graph', $family->slug))->assertJsonPath('nodes.0.attributes.0.color', '#EFEFEF')->assertJsonPath('nodes.0.attributes.0.text_color', '#111111');
    $attribute->update(['is_active' => false]);
    $this->getJson(route('genealogy.graph', $family->slug))->assertJsonCount(0, 'nodes.0.attributes');
    expect($person->personAttributes()->count())->toBe(1);
    // Omitting metadata in older forms preserves it.
    $this->put(route('management.people.update', [$family->slug, $person->id]), descriptorPersonData())->assertSessionHasNoErrors();
    expect($person->fresh()->title)->toBe('لقب اختباري')->and($person->personAttributes()->count())->toBe(1);
    $this->put(route('management.people.update', [$family->slug, $person->id]), descriptorPersonData(['title' => null, 'attributes_present' => 1]))->assertSessionHasNoErrors();
    expect($person->fresh()->title)->toBeNull()->and($person->personAttributes()->count())->toBe(0);
});

test('attribute assignments are audited and can be reverted with person metadata', function () {
    [$family, $tree, $super, $admin] = descriptorContext();
    $attribute = PersonAttribute::query()->where('key', 'companion')->sole();
    $this->actingAs($admin)->post(route('management.people.store', $family->slug), descriptorPersonData())->assertSessionHasNoErrors();
    $person = $tree->people()->sole();
    $this->put(route('management.people.update', [$family->slug, $person->id]), descriptorPersonData(['title' => 'محدث', 'attribute_ids' => [$attribute->id]]))->assertSessionHasNoErrors();
    $log = AuditLog::query()->where('auditable_type', Person::class)->where('auditable_id', $person->id)->latest('id')->firstOrFail();
    expect($log->after['attribute_ids'])->toBe([$attribute->id]);
    $this->get(route('management.audit.show', $log->id))->assertOk();
    app(AuditReversionService::class)->revert($log, $admin, 'استرجاع بيانات الاختبار');
    expect($person->fresh()->title)->toBeNull()->and($person->personAttributes()->count())->toBe(0);
});

test('titles and descriptors do not expose living or pending people on public endpoints', function () {
    [$family, $tree, $super, $admin] = descriptorContext();
    $attribute = PersonAttribute::query()->where('key', 'companion')->sole();
    $person = $tree->people()->create(descriptorPersonData(['title' => 'لقب سري', 'is_living' => true]));
    $person->personAttributes()->attach($attribute);
    $tree->update(['root_person_id' => $person->id]);
    $this->get(route('genealogy.person', [$family->slug, $person->id]))->assertNotFound();
    $this->getJson(route('genealogy.graph', $family->slug))->assertNotFound();
    $this->getJson(route('genealogy.search', [$family->slug, 'q' => 'شخص']))->assertJsonCount(0, 'data');
    $this->actingAs($admin)->getJson(route('genealogy.graph', [$family->slug, 'private' => 1]))->assertOk()->assertJsonPath('nodes.0.title', 'لقب سري');
    $person->update(['is_living' => false, 'verification_status' => 'pending_review']);
    $this->get(route('genealogy.person', [$family->slug, $person->id]))->assertNotFound();
});
