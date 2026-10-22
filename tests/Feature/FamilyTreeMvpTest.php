<?php

use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\User;
use App\Services\Genealogy\BranchFocusService;
use App\Services\Genealogy\BranchMembershipService;
use Database\Seeders\PersonAttributeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{family: Family, tree: FamilyTree, root: Person, admin: User, editor: User, reader: User} */
function mvpFamily(string $slug = 'mvp'): array
{
    $family = Family::query()->create(['name_ar' => 'عائلة '.$slug, 'slug' => $slug]);
    $tree = $family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $root = $tree->people()->create(['first_name_ar' => 'الجد', 'full_name_ar' => 'الجد المؤسس', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $tree->update(['root_person_id' => $root->id]);
    $users = [];
    foreach (['admin', 'editor', 'reader'] as $role) {
        $users[$role] = User::factory()->create();
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $users[$role]->id, 'role' => $role]);
    }

    return ['family' => $family, 'tree' => $tree->fresh(), 'root' => $root, ...$users];
}

/** @return array<string, mixed> */
function mvpPerson(string $name, ?Person $father, string $verification = 'verified', array $extra = []): array
{
    return [
        'first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'is_living' => 0,
        'verification_status' => $verification, 'father_id' => $father?->id, 'father_verification_status' => $verification, ...$extra,
    ];
}

test('an administrator adds a published child and the public tree person pages and search show it', function () {
    $context = mvpFamily();

    $this->actingAs($context['admin'])->post(route('management.people.store', 'mvp'), mvpPerson('سليمان', $context['root']))->assertSessionHasNoErrors();
    $child = $context['tree']->people()->where('full_name_ar', 'سليمان')->sole();
    auth()->logout();

    $this->get(route('genealogy.person', ['mvp', $child->id]))->assertOk()->assertSee('سليمان')->assertSee('الجد المؤسس');
    $this->get(route('genealogy.person', ['mvp', $context['root']->id]))->assertOk()->assertSee(route('genealogy.person', ['mvp', $child->id, 'tree' => 'main']), false);
    $this->getJson(route('genealogy.search', ['mvp', 'q' => 'سليمان']))->assertOk()->assertJsonPath('data.0.id', $child->id);
    $graph = $this->getJson(route('genealogy.graph', 'mvp'))->assertOk();
    expect(collect($graph->json('nodes'))->pluck('id'))->toContain($child->id)
        ->and(collect($graph->json('edges'))->where('child_id', $child->id)->pluck('parent_id')->all())->toBe([$context['root']->id]);
});

test('an editor can add unpublished people but cannot publish them directly', function () {
    $context = mvpFamily();
    $this->actingAs($context['editor']);

    $this->post(route('management.people.store', 'mvp'), mvpPerson('منشور', $context['root']))
        ->assertSessionHasErrors(['verification_status' => 'اعتماد حالة «موثق» أو «مسند» للنشر من صلاحية مدير العائلة؛ احفظ السجل «قيد المراجعة» أو قدّم طلب تغيير.']);
    expect($context['tree']->people()->where('full_name_ar', 'منشور')->exists())->toBeFalse();

    $this->post(route('management.people.store', 'mvp'), mvpPerson('مسودة', $context['root'], 'pending_review'))->assertSessionHasNoErrors();
    $draft = $context['tree']->people()->where('full_name_ar', 'مسودة')->sole();
    auth()->logout();
    $this->get(route('genealogy.person', ['mvp', $draft->id]))->assertNotFound();
});

test('an editor cannot change or delete published records directly but can propose the change', function () {
    $context = mvpFamily();
    $published = $context['tree']->people()->create(['first_name_ar' => 'منشور', 'full_name_ar' => 'منشور', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $edge = $context['tree']->personRelationships()->create(['person_id' => $published->id, 'related_person_id' => $context['root']->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'verified']);
    $this->actingAs($context['editor']);

    $this->put(route('management.people.update', ['mvp', $published->id]), mvpPerson('اسم معدل', $context['root'], 'pending_review'))
        ->assertSessionHasErrors(['change' => 'هذا السجل منشور للعامة؛ تعديله أو حذفه يمر بطلب تغيير يعتمده مدير العائلة.']);
    $this->delete(route('management.relationships.destroy', ['mvp', $edge->id]))->assertSessionHasErrors('change');
    expect($published->fresh()->full_name_ar)->toBe('منشور')->and($edge->fresh())->not->toBeNull();

    $this->post(route('management.changes.store', 'mvp'), ['kind' => 'person', 'operation' => 'update', 'target_id' => $published->id, 'reason' => 'تصحيح من مصدر', 'full_name_ar' => 'اسم معدل'])->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    $this->actingAs($context['admin'])->post(route('management.changes.decide', ['mvp', $change->id]), ['decision' => 'accepted', 'decision_reason' => 'تحقق المدير'])->assertSessionHasNoErrors();
    expect($published->fresh()->full_name_ar)->toBe('اسم معدل');
});

test('editors cannot publish relationships or branches while administrators can', function () {
    $context = mvpFamily();
    $child = $context['tree']->people()->create(['first_name_ar' => 'ابن', 'full_name_ar' => 'ابن', 'status' => 'historical', 'verification_status' => 'pending_review']);

    $this->actingAs($context['editor'])->post(route('management.relationships.store', 'mvp'), ['person_id' => $child->id, 'father_id' => $context['root']->id, 'verification_status' => 'verified'])
        ->assertSessionHasErrors('verification_status');
    $this->post(route('management.branches.store', 'mvp'), ['name_ar' => 'فخذ', 'slug' => 'fakhd', 'root_person_id' => $context['root']->id, 'is_active' => 1, 'verification_status' => 'sourced'])
        ->assertSessionHasErrors('verification_status');
    $this->post(route('management.branches.store', 'mvp'), ['name_ar' => 'فخذ مسودة', 'slug' => 'draft', 'root_person_id' => $context['root']->id, 'is_active' => 1, 'verification_status' => 'pending_review'])
        ->assertSessionHasNoErrors();

    $this->actingAs($context['admin'])->post(route('management.relationships.store', 'mvp'), ['person_id' => $child->id, 'father_id' => $context['root']->id, 'verification_status' => 'verified'])
        ->assertSessionHasNoErrors();
    expect($context['tree']->personRelationships()->sole()->verification_status)->toBe('verified');
});

test('person forms mark publishing statuses as administrator decisions for editors only', function () {
    $context = mvpFamily();
    $url = route('management.records.create', ['mvp', 'people']);

    $this->actingAs($context['editor'])->get($url)->assertOk()->assertSee('موثق — يعتمده مدير العائلة');
    $this->actingAs($context['admin'])->get($url)->assertOk()->assertDontSee('يعتمده مدير العائلة');
});

test('a same name sibling needs explicit confirmation while the same name under another father does not', function () {
    $context = mvpFamily();
    $this->actingAs($context['admin'])->post(route('management.people.store', 'mvp'), mvpPerson('محمد', $context['root']))->assertSessionHasNoErrors();
    $other = $context['tree']->people()->create(['first_name_ar' => 'علي', 'full_name_ar' => 'علي', 'status' => 'historical', 'verification_status' => 'verified']);

    $this->post(route('management.people.store', 'mvp'), mvpPerson('مُحمّد', $context['root']))->assertSessionHasErrors('confirm_distinct');
    expect($context['tree']->people()->where('first_name_ar', 'مُحمّد')->exists())->toBeFalse();

    $this->post(route('management.people.store', 'mvp'), mvpPerson('محمد', $other))->assertSessionHasNoErrors();
    $this->post(route('management.people.store', 'mvp'), mvpPerson('محمد', $context['root'], 'verified', ['confirm_distinct' => 1]))->assertSessionHasNoErrors();
    expect($context['tree']->people()->where('full_name_ar', 'محمد')->count())->toBe(3);
});

test('branches carry a configurable hierarchy type and derive members without copying people', function () {
    $context = mvpFamily();
    $this->actingAs($context['admin'])->post(route('management.people.store', 'mvp'), mvpPerson('قاسم', $context['root']))->assertSessionHasNoErrors();
    $founder = $context['tree']->people()->where('full_name_ar', 'قاسم')->sole();
    $this->post(route('management.people.store', 'mvp'), mvpPerson('حمدان', $founder))->assertSessionHasNoErrors();
    $peopleBefore = Person::query()->count();

    $this->post(route('management.branches.store', 'mvp'), ['name_ar' => 'القواسمة', 'slug' => 'qawasmeh', 'root_person_id' => $founder->id, 'branch_type' => 'fakhd', 'is_active' => 1, 'verification_status' => 'verified'])
        ->assertSessionHasNoErrors();
    $this->post(route('management.branches.store', 'mvp'), ['name_ar' => 'غير صالح', 'slug' => 'invalid', 'root_person_id' => $founder->id, 'branch_type' => 'kingdom', 'verification_status' => 'pending_review'])
        ->assertSessionHasErrors('branch_type');

    $branch = Branch::query()->where('slug', 'qawasmeh')->sole();
    expect($branch->branch_type)->toBe('fakhd')->and($branch->type_label)->toBe('فخذ')
        ->and(app(BranchMembershipService::class)->members($branch)->pluck('full_name_ar')->all())->toBe(['قاسم', 'حمدان'])
        ->and(Person::query()->count())->toBe($peopleBefore);
    auth()->logout();
    $this->get(route('genealogy.tree', 'mvp'))->assertOk()->assertSee('القواسمة — فخذ');
});

test('a nested fakhd focus shows each shared ancestor once', function () {
    $context = mvpFamily();
    $this->actingAs($context['admin']);
    foreach ([['سليمان', 'root'], ['قاسم', 'سليمان'], ['محمد', 'قاسم']] as [$name, $fatherName]) {
        $father = $fatherName === 'root' ? $context['root'] : $context['tree']->people()->where('full_name_ar', $fatherName)->sole();
        $this->post(route('management.people.store', 'mvp'), mvpPerson($name, $father))->assertSessionHasNoErrors();
    }
    $people = $context['tree']->people()->pluck('id', 'full_name_ar');
    $this->post(route('management.branches.store', 'mvp'), ['name_ar' => 'عشيرة', 'slug' => 'clan', 'branch_type' => 'clan', 'root_person_id' => $people['سليمان'], 'is_active' => 1, 'verification_status' => 'verified'])->assertSessionHasNoErrors();
    $clan = Branch::query()->where('slug', 'clan')->sole();
    $this->post(route('management.branches.store', 'mvp'), ['name_ar' => 'فخذ', 'slug' => 'fakhd', 'branch_type' => 'fakhd', 'parent_branch_id' => $clan->id, 'root_person_id' => $people['قاسم'], 'is_active' => 1, 'verification_status' => 'verified'])->assertSessionHasNoErrors();

    $nodes = collect(app(BranchFocusService::class)->getBranchFocus(Branch::query()->where('slug', 'fakhd')->sole()->id)['nodes'])->pluck('person.id');
    expect($nodes->duplicates())->toBeEmpty()->and($nodes->all())->toContain($people['الجد المؤسس'], $people['سليمان'], $people['قاسم'], $people['محمد']);
});

test('approved founder badges are seeded and a deactivated badge disappears from public pages', function () {
    app(PersonAttributeSeeder::class)->run();
    $context = mvpFamily();
    $badge = PersonAttribute::query()->where('key', 'fakhd-founder')->sole();
    $context['root']->personAttributes()->attach($badge->id);

    expect(PersonAttribute::query()->whereIn('key', ['tribe-founder', 'clan-founder', 'fakhd-founder', 'sub-branch-founder'])->count())->toBe(4);
    $this->get(route('genealogy.person', ['mvp', $context['root']->id]))->assertSee('مؤسس الفخذ');
    $badge->update(['is_active' => false]);
    $this->get(route('genealogy.person', ['mvp', $context['root']->id]))->assertDontSee('مؤسس الفخذ');
    expect($context['root']->personAttributes()->count())->toBe(1);
});

test('static Al-Zoubi branch pages link to the database branch only when it is published', function () {
    $family = Family::query()->create(['name_ar' => 'الزعبي', 'slug' => 'al-zoubi']);
    $tree = $family->familyTrees()->create(['name_ar' => 'الشجرة', 'slug' => 'main', 'is_primary' => true]);
    $founder = $tree->people()->create(['first_name_ar' => 'بكر', 'full_name_ar' => 'بكر', 'status' => 'historical', 'verification_status' => 'verified', 'is_living' => false]);
    $branch = $tree->branches()->create(['name_ar' => 'بكر', 'slug' => 'bakr', 'root_person_id' => $founder->id, 'is_active' => true, 'verification_status' => 'pending_review']);
    $link = route('genealogy.branch', ['al-zoubi', 'bakr']);

    $this->get('/al-zoubi/branches/bakr')->assertOk()->assertDontSee($link, false);
    $branch->update(['verification_status' => 'verified']);
    $this->get('/al-zoubi/branches/bakr')->assertOk()->assertSee($link, false);
    $this->get('/al-zoubi/branches')->assertOk()->assertSee($link, false);
    $this->get($link)->assertOk();
});

test('tree search distinguishes repeated names by lineage without naming unpublished ancestors', function () {
    $context = mvpFamily();
    $this->actingAs($context['admin']);
    $this->post(route('management.people.store', 'mvp'), mvpPerson('سليمان', $context['root']))->assertSessionHasNoErrors();
    $this->post(route('management.people.store', 'mvp'), mvpPerson('قاسم', $context['root']))->assertSessionHasNoErrors();
    $people = $context['tree']->people()->pluck('id', 'full_name_ar');
    $this->post(route('management.people.store', 'mvp'), mvpPerson('محمد', $context['tree']->people()->find($people['سليمان'])))->assertSessionHasNoErrors();
    $this->post(route('management.people.store', 'mvp'), mvpPerson('محمد', $context['tree']->people()->find($people['قاسم'])))->assertSessionHasNoErrors();
    $context['tree']->people()->whereKey($people['قاسم'])->update(['verification_status' => 'pending_review']);
    auth()->logout();

    $results = collect($this->getJson(route('genealogy.search', ['mvp', 'q' => 'محمد']))->assertOk()->json('data'));

    expect($results->pluck('text')->unique()->all())->toBe(['محمد'])
        ->and($results->pluck('lineage')->sort()->values()->all())->toBe([null, 'بن سليمان بن الجد المؤسس']);
});

test('person creation proposals require confirmation for same name records without a father, including recorded variants', function () {
    $context = mvpFamily();
    $context['tree']->people()->create(['first_name_ar' => 'ذيبان', 'full_name_ar' => 'ذيبان', 'alternate_names' => ['ذبيان'], 'status' => 'historical', 'verification_status' => 'pending_review']);
    $proposal = fn (string $name, array $extra = []) => ['kind' => 'person', 'operation' => 'create', 'reason' => 'اقتراح', 'first_name_ar' => $name, 'full_name_ar' => $name, 'status' => 'historical', 'verification_status' => 'pending_review', ...$extra];
    $this->actingAs($context['reader']);

    $this->post(route('management.changes.store', 'mvp'), $proposal('ذَبيان'))->assertSessionHasErrors('confirm_distinct');
    $this->post(route('management.changes.store', 'mvp'), $proposal('ذيبان'))->assertSessionHasErrors('confirm_distinct');
    expect(ChangeRequest::query()->count())->toBe(0);

    $this->post(route('management.changes.store', 'mvp'), $proposal('ذيبان', ['confirm_distinct' => 1]))->assertSessionHasNoErrors();
    $change = ChangeRequest::query()->sole();
    $this->actingAs($context['admin'])->get(route('management.changes.show', ['mvp', $change->id]))->assertOk()
        ->assertSee('سجلات قائمة بالاسم نفسه')->assertSee('name="confirm_distinct"', false);

    $this->post(route('management.changes.decide', ['mvp', $change->id]), ['decision' => 'accepted', 'decision_reason' => 'قبول'])->assertSessionHasErrors('confirm_distinct');
    expect($change->fresh()->status)->toBe('pending')->and($context['tree']->people()->where('full_name_ar', 'ذيبان')->count())->toBe(1);

    $this->post(route('management.changes.decide', ['mvp', $change->id]), ['decision' => 'accepted', 'decision_reason' => 'شخص مختلف بعد المراجعة', 'confirm_distinct' => 1])->assertSessionHasNoErrors();
    expect($change->fresh()->status)->toBe('accepted')->and($context['tree']->people()->where('full_name_ar', 'ذيبان')->count())->toBe(2);
});

test('a proposal is not flagged when the same name exists only under a recorded father', function () {
    $context = mvpFamily();
    $this->actingAs($context['admin'])->post(route('management.people.store', 'mvp'), mvpPerson('سليمان', $context['root']))->assertSessionHasNoErrors();

    $this->actingAs($context['reader'])->post(route('management.changes.store', 'mvp'), ['kind' => 'person', 'operation' => 'create', 'reason' => 'اقتراح', 'first_name_ar' => 'سليمان', 'full_name_ar' => 'سليمان', 'status' => 'historical', 'verification_status' => 'pending_review'])
        ->assertSessionHasNoErrors();
    $this->actingAs($context['admin'])->post(route('management.changes.decide', ['mvp', ChangeRequest::query()->sole()->id]), ['decision' => 'accepted', 'decision_reason' => 'قبول'])->assertSessionHasNoErrors();
    expect($context['tree']->people()->where('full_name_ar', 'سليمان')->count())->toBe(2);
});
