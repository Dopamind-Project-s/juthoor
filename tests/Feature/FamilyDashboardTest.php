<?php

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{family: Family, tree: FamilyTree, admin: User, editor: User, reader: User} */
function dashboardFamily(string $slug = 'dash'): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة '.$slug, 'slug' => $slug]);
    $tree = $family->familyTrees()->create(['name_ar' => 'الشجرة '.$slug, 'slug' => 'main', 'is_primary' => true]);
    $users = [];
    foreach (['admin', 'editor', 'reader'] as $role) {
        $users[$role] = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $users[$role]->id, 'role' => $role]);
    }

    return ['family' => $family, 'tree' => $tree, ...$users];
}

function dashboardPerson(FamilyTree $tree, string $name, ?Person $father = null, array $attributes = []): Person
{
    $person = $tree->people()->create(['first_name_ar' => $name, 'full_name_ar' => $name, ...$attributes]);
    if ($father) {
        $tree->personRelationships()->create(['person_id' => $person->id, 'related_person_id' => $father->id, 'relationship_type' => 'father', 'is_primary' => true]);
    }

    return $person;
}

test('guests are sent to sign in and non members cannot open a family dashboard', function () {
    $context = dashboardFamily();
    $url = route('management.families.dashboard', $context['family']->slug);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs(dashboardFamily('other')['admin'])->get($url)->assertForbidden();
});

test('a disabled family dashboard is closed to its members but open to a super admin', function () {
    $context = dashboardFamily();
    $context['family']->update(['is_active' => false]);
    $super = User::factory()->create()->forceFill(['is_super_admin' => true]);
    $super->save();

    $this->actingAs($context['admin'])->get(route('management.families.dashboard', 'dash'))->assertForbidden();
    $this->actingAs($super)->get(route('management.families.dashboard', 'dash'))->assertOk()->assertSee('العائلة معطلة');
});

test('every family role sees statistics computed only from its own family tree', function (string $role) {
    $context = dashboardFamily();
    $root = dashboardPerson($context['tree'], 'الجد');
    dashboardPerson($context['tree'], 'الابن', $root);
    $context['tree']->update(['root_person_id' => $root->id]);
    $other = dashboardFamily('other');
    foreach (range(1, 5) as $index) {
        dashboardPerson($other['tree'], 'غريب '.$index);
    }

    $this->actingAs($context[$role])->get(route('management.families.dashboard', 'dash'))
        ->assertOk()
        ->assertViewHas('statistics', fn (array $statistics) => $statistics['people'] === 2 && $statistics['relationships'] === 1)
        ->assertSee('عائلة dash')
        ->assertDontSee('غريب 1')
        ->assertDontSee(route('management.families.dashboard', 'other'));
})->with(['admin', 'editor', 'reader']);

test('quick actions and administrative panels follow the family role', function () {
    $context = dashboardFamily();
    $url = route('management.families.dashboard', 'dash');

    $this->actingAs($context['reader'])->get($url)
        ->assertSee('اقتراح تغيير')->assertDontSee('إضافة شخص')
        ->assertDontSee('الأعضاء والأدوار')->assertViewHas('pendingInvitations', null);
    $this->actingAs($context['editor'])->get($url)
        ->assertSee('إضافة شخص')->assertSee('استيراد CSV')->assertDontSee('دعوة عضو')->assertDontSee('الأعضاء والأدوار');
    $this->actingAs($context['admin'])->get($url)
        ->assertSee('دعوة عضو')->assertSee('الأعضاء والأدوار')
        ->assertViewHas('members', ['admin' => 1, 'editor' => 1, 'reader' => 1]);
});

test('health indicators count orphans duplicates missing sources and unknown living status', function () {
    $context = dashboardFamily();
    $tree = $context['tree'];
    $root = dashboardPerson($tree, 'الجد', null, ['is_living' => false]);
    $tree->update(['root_person_id' => $root->id]);
    dashboardPerson($tree, 'محمد', $root, ['is_living' => false]);
    dashboardPerson($tree, 'محمد', $root, ['is_living' => false]);
    dashboardPerson($tree, 'يتيم');
    $otherFather = dashboardPerson($tree, 'علي', $root, ['is_living' => false]);
    dashboardPerson($tree, 'محمد', $otherFather, ['is_living' => false]);

    $this->actingAs($context['admin'])->get(route('management.families.dashboard', 'dash'))
        ->assertOk()
        ->assertViewHas('health', function (array $health) {
            $counts = collect($health)->pluck('count', 'key');

            return $counts['without_father'] === 1
                && $counts['possible_duplicates'] === 2
                && $counts['living_unknown'] === 1
                && $counts['without_sources'] === 6
                && $counts['cross_tree_relationships'] === 0;
        })
        ->assertViewHas('duplicateGroups', [['name' => 'محمد', 'father' => 'الجد', 'count' => 2]]);
});

test('health drill down filters the people list inside the selected tree only', function () {
    $context = dashboardFamily();
    $root = dashboardPerson($context['tree'], 'الجد', null, ['is_living' => false]);
    $context['tree']->update(['root_person_id' => $root->id]);
    dashboardPerson($context['tree'], 'يتيم معروف');
    dashboardPerson($context['tree'], 'ابن موثق', $root);
    dashboardPerson(dashboardFamily('other')['tree'], 'يتيم غريب');

    $this->actingAs($context['reader'])->get(route('management.sections.index', ['dash', 'people', 'issue' => 'without_father']))
        ->assertOk()->assertSee('يتيم معروف')->assertDontSee('ابن موثق')->assertDontSee('يتيم غريب');
    $this->get(route('management.sections.index', ['dash', 'people', 'issue' => 'drop table']))
        ->assertSessionHasErrors('issue');
});

test('the selected tree scopes the dashboard and a tree of another family is not found', function () {
    $context = dashboardFamily();
    $second = $context['family']->familyTrees()->create(['name_ar' => 'شجرة ثانية', 'slug' => 'second']);
    dashboardPerson($second, 'في الثانية');
    dashboardPerson($context['tree'], 'في الأولى');
    $foreign = dashboardFamily('other')['family']->familyTrees()->create(['name_ar' => 'أجنبية', 'slug' => 'foreign']);

    $this->actingAs($context['editor'])->get(route('management.families.dashboard', ['dash', 'tree' => 'second']))
        ->assertOk()->assertViewHas('tree', fn (FamilyTree $tree) => $tree->is($second))
        ->assertViewHas('statistics', fn (array $statistics) => $statistics['people'] === 1);
    $this->get(route('management.families.dashboard', ['dash', 'tree' => $foreign->slug]))->assertNotFound();
});

test('recent changes hide reviewer only and membership audit entries from readers', function () {
    $context = dashboardFamily();
    $base = ['family_id' => $context['family']->id, 'auditable_id' => 1, 'action' => 'updated'];
    AuditLog::query()->create([...$base, 'auditable_type' => Person::class]);
    AuditLog::query()->create([...$base, 'auditable_type' => FamilyMembership::class]);
    AuditLog::query()->create([...$base, 'auditable_type' => Person::class, 'reviewers_only' => true]);

    $this->actingAs($context['reader'])->get(route('management.families.dashboard', 'dash'))
        ->assertViewHas('recentAudit', fn ($logs) => $logs->count() === 1 && $logs->first()->auditable_type === Person::class && ! $logs->first()->reviewers_only);
    $this->actingAs($context['admin'])->get(route('management.families.dashboard', 'dash'))
        ->assertViewHas('recentAudit', fn ($logs) => $logs->count() === 3);
});

test('the dashboard query count does not grow with the size of the tree', function () {
    $measure = function (int $people): int {
        $context = dashboardFamily('size'.$people);
        $root = dashboardPerson($context['tree'], 'الجد');
        foreach (range(1, $people) as $index) {
            dashboardPerson($context['tree'], 'فرد '.$index, $root);
        }
        $this->actingAs($context['admin']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('management.families.dashboard', 'size'.$people))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($measure(3))->toBe($measure(60));
});

test('the family workspace navigation is scoped to the family and separated from site administration', function () {
    $context = dashboardFamily();
    $second = dashboardFamily('second');
    FamilyMembership::query()->create(['family_id' => $second['family']->id, 'user_id' => $context['reader']->id, 'role' => 'editor']);
    dashboardFamily('hidden');

    $response = $this->actingAs($context['reader'])->get(route('management.families.dashboard', 'dash'));
    $response->assertOk()
        ->assertSee('aria-label="مسار التنقل"', false)
        ->assertSee(route('management.sections.index', ['dash', 'people', 'tree' => 'main']), false)
        ->assertSee('عائلة dash — قارئ')->assertSee('عائلة second — محرر')->assertDontSee('عائلة hidden')
        ->assertDontSee(route('management.sections.index', ['dash', 'members', 'tree' => 'main']), false)
        ->assertDontSee(route('site-admin.dashboard'), false)
        ->assertDontSee(route('management.families.dashboard', 'second').'?tree', false);

    $super = User::factory()->create()->forceFill(['is_super_admin' => true]);
    $super->save();
    $this->actingAs($super)->get(route('management.families.dashboard', 'dash'))
        ->assertSee('الانتقال إلى إدارة الموقع')
        ->assertDontSee(route('site-admin.resources.index', 'users'), false);
    $this->get(route('site-admin.dashboard'))
        ->assertSee('data-workspace="site"', false)
        ->assertSee(route('site-admin.resources.index', 'users'), false)
        ->assertDontSee(route('management.sections.index', ['dash', 'people', 'tree' => 'main']), false);
});
