<?php

use App\Models\Branch;
use App\Models\Citation;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\FamilyRoleAssignment;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Role;
use App\Models\Source;
use App\Models\User;
use App\Services\Imports\UnifiedGenealogyDataset;
use Database\Seeders\AlZoubiFamilyTreeSeeder;
use Database\Seeders\SiteUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('the complete default import is connected private traceable scoped and repeatable', function () {
    $data = app(UnifiedGenealogyDataset::class)->data();
    $this->seed();
    expect(Person::count())->toBe(count($data['people']))->toBeGreaterThan(1000)
        ->and(PersonRelationship::count())->toBe(count($data['people']) - 1)
        ->and(Family::count())->toBe(1)->and(FamilyTree::count())->toBe(1)
        ->and(Branch::count())->toBe(count($data['branches']))
        ->and(CommunityEntity::count())->toBe(count($data['branches']) + 1);
    $people = Person::all()->keyBy('canonical_key');
    foreach ($data['people'] as $row) {
        $person = $people[$row['key']];
        expect($person->full_name_ar)->toBe($row['name'])
            ->and($person->sources()->exists())->toBeTrue()
            ->and($person->verification_status)->toBe('pending_review');
        if ($row['father']) {
            expect($person->outgoingRelationships()->where('relationship_type', 'father')->sole()->related_person_id)->toBe($people[$row['father']]->id);
        }
    }
    expect($people['ROOT-076']->full_name_ar)->toBe('يوسف')
        ->and($people['ROOT-077']->full_name_ar)->toBe('ذيبان')
        ->and($people['ROOT-078']->full_name_ar)->toBe('سليمان')
        ->and($people['QW-0031']->title)->toBe('مؤسس النظام')
        ->and($people['QW-0031']->is_living)->toBeNull()
        ->and(Person::where('canonical_key', 'like', 'QW-%')->publiclyVisible()->count())->toBe(0)
        ->and(Person::where('canonical_key', 'like', 'AZ-DESC-%')->publiclyVisible()->count())->toBe(0)
        ->and(Branch::where('slug', 'al-awtalat')->sole()->root_person_id)->toBeNull()
        ->and(Branch::where('slug', 'like', 'qawasmeh-%')->count())->toBe(4)
        ->and(Person::where('full_name_ar', 'محمد')->count())->toBeGreaterThan(20);
    $children = fn (string $key) => PersonRelationship::where('related_person_id', $people[$key]->id)->where('relationship_type', 'father')->with('person')->get()->pluck('person.canonical_key')->sort()->values()->all();
    expect($children('QW-0004'))->toBe(['QW-0005', 'QW-0006', 'QW-0007', 'QW-0008'])
        ->and($children('QW-0017'))->toBe(['QW-0023', 'QW-0024', 'QW-0025', 'QW-0026', 'QW-0027', 'QW-0028', 'QW-0029', 'QW-0030'])
        ->and($children('QW-0024'))->toBe(['QW-0031', 'QW-0032', 'QW-0033', 'QW-0034']);
    expect($people['AZ-BAKR-ALI']->outgoingRelationships()->where('relationship_type', 'father')->sole()->related_person_id)->toBe($people['ROOT-077']->id);
    foreach (Role::assignable()->get() as $role) {
        expect(FamilyRoleAssignment::where('role_id', $role->id)->count())->toBe(1);
    }
    foreach (User::all() as $user) {
        expect(str_ends_with($user->email, '.test'))->toBeTrue()->and(Hash::check('password', $user->password))->toBeTrue();
    }
    expect(Source::where('verification_status', 'verified')->count())->toBe(0)
        ->and(Citation::where('field_key', 'source_observation')->count())->toBe(count(json_decode(file_get_contents(resource_path('data/genealogy/al-zoubi/review-queue.json')), true)));
    $tables = ['families', 'family_trees', 'people', 'person_relationships', 'branches', 'community_entities', 'sources', 'citations', 'assertions', 'audit_logs', 'users', 'family_role_assignments'];
    $counts = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    $people['QW-0031']->update(['title' => 'وصف مراجع', 'is_living' => true]);
    $people['ROOT-049']->personAttributes()->detach();
    FamilyRoleAssignment::firstOrFail()->delete();
    $counts['family_role_assignments']--;
    $this->seed();
    expect(collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all())->toBe($counts)
        ->and($people['QW-0031']->fresh()->title)->toBe('وصف مراجع')
        ->and($people['QW-0031']->fresh()->is_living)->toBeTrue()
        ->and($people['ROOT-049']->fresh()->personAttributes()->count())->toBe(0);
    $this->actingAs(User::where('username', 'superadmin')->sole());
    $this->get('/families/al-zoubi/portal')->assertOk();
    $this->get('/families/al-zoubi/tree')->assertOk();
});

test('normalized dataset rejects duplicates missing parents cycles and disconnected roots', function (string $mutation) {
    $loader = app(UnifiedGenealogyDataset::class);
    $data = $loader->data();
    match ($mutation) {
        'duplicate' => $data['people'][] = $data['people'][0],
        'missing' => $data['people'][1]['father'] = 'missing',
        'cycle' => $data['people'][1]['father'] = $data['people'][2]['key'],
        'disconnected' => $data['people'][1]['father'] = null,
    };
    expect(fn () => $loader->validate($data))->toThrow(DomainException::class);
})->with(['duplicate', 'missing', 'cycle', 'disconnected']);

test('demo accounts refuse production and public deployments', function (string $environment, string $url) {
    app()->detectEnvironment(fn () => $environment);
    config(['app.url' => $url]);
    expect(fn () => (new SiteUsersSeeder)->run())->toThrow(DomainException::class);
    expect(User::count())->toBe(0);
})->with([['production', 'https://juthoor.example'], ['staging', 'http://localhost'], ['local', 'https://public.example']]);

test('initialization refuses a nonempty database without dropping records', function () {
    $family = Family::create(['slug' => 'preserve-me', 'name_ar' => 'عائلة محفوظة']);
    $this->artisan('genealogy:initialize-development')->assertFailed();
    expect($family->fresh())->not->toBeNull();
});

test('unmanaged stable-key collisions are rejected atomically', function () {
    $family = Family::create(['slug' => 'al-zoubi', 'name_ar' => 'الزعبي']);
    $tree = $family->familyTrees()->create(['slug' => 'main', 'name_ar' => 'الشجرة', 'is_primary' => true, 'is_active' => true]);
    $person = $tree->people()->create(['canonical_key' => 'ROOT-001', 'full_name_ar' => 'هوية مختلفة', 'first_name_ar' => 'هوية']);
    expect(fn () => (new AlZoubiFamilyTreeSeeder)->run())->toThrow(DomainException::class, 'Unmanaged identity collision');
    expect(Person::count())->toBe(1)->and($person->fresh()->full_name_ar)->toBe('هوية مختلفة')->and(Source::count())->toBe(0);
});
