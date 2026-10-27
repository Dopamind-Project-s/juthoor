<?php

use App\Models\CommunityEntity;
use App\Models\EntityNarrative;
use App\Models\Family;
use App\Models\NewsPost;
use App\Models\Person;
use App\Models\PersonRelationship;
use Database\Seeders\Fixtures\JuthoorUiDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\LegacyGenealogySeeder;

uses(RefreshDatabase::class);

test('demo fixtures need the explicit opt-in and refuse production even when opted in', function () {
    config(['seeding.demo_fixtures' => false]);
    expect(fn () => $this->seed(JuthoorUiDemoSeeder::class))->toThrow(RuntimeException::class, 'explicit opt-in');

    config(['seeding.demo_fixtures' => true]);
    $this->app->instance('env', 'production');
    expect(fn () => app(JuthoorUiDemoSeeder::class)->run())->toThrow(RuntimeException::class, 'local/testing');
    expect(Family::query()->count())->toBe(0);
});

test('db:seed never runs the demo fixtures', function () {
    config(['seeding.demo_fixtures' => true]);
    $this->seed(LegacyGenealogySeeder::class);

    expect(Family::query()->where('slug', JuthoorUiDemoSeeder::SLUG)->exists())->toBeFalse();
});

test('demo fixtures are synthetic unpublished isolated from real families and idempotent', function () {
    $this->seed(LegacyGenealogySeeder::class);
    $zoubi = Family::query()->where('slug', 'al-zoubi')->sole();
    $zoubiPeople = Person::query()->whereIn('family_tree_id', $zoubi->familyTrees()->select('id'))->get()->map->getAttributes()->all();
    config(['seeding.demo_fixtures' => true]);

    $this->seed(JuthoorUiDemoSeeder::class);
    $counts = [Family::query()->count(), Person::query()->count(), PersonRelationship::query()->count(), CommunityEntity::query()->count(), NewsPost::query()->count(), EntityNarrative::query()->count()];
    $this->seed(JuthoorUiDemoSeeder::class);

    $demo = Family::query()->where('slug', JuthoorUiDemoSeeder::SLUG)->sole();
    $people = Person::query()->whereIn('family_tree_id', $demo->familyTrees()->select('id'))->get();
    expect([Family::query()->count(), Person::query()->count(), PersonRelationship::query()->count(), CommunityEntity::query()->count(), NewsPost::query()->count(), EntityNarrative::query()->count()])->toBe($counts)
        ->and($demo->name_ar)->toContain(JuthoorUiDemoSeeder::LABEL)
        ->and($people)->toHaveCount(count(JuthoorUiDemoSeeder::PEOPLE))
        ->and($people->every(fn (Person $person) => $person->notes === JuthoorUiDemoSeeder::LABEL && $person->is_living === null && $person->verification_status === 'pending_review'))->toBeTrue()
        ->and(Person::query()->whereIn('family_tree_id', $demo->familyTrees()->select('id'))->publiclyVisible()->count())->toBe(0)
        ->and(CommunityEntity::query()->where('family_id', $demo->id)->where('publication_status', '!=', 'draft')->count())->toBe(0)
        ->and(NewsPost::query()->where('publication_status', '!=', 'draft')->count())->toBe(0)
        ->and(NewsPost::query()->whereNotNull('contact_phone')->count())->toBe(0)
        ->and(CommunityEntity::query()->where('family_id', $zoubi->id)->count())->toBe(0)
        ->and(Person::query()->whereIn('family_tree_id', $zoubi->familyTrees()->select('id'))->get()->map->getAttributes()->all())->toBe($zoubiPeople);

    $this->get(route('genealogy.person', [JuthoorUiDemoSeeder::SLUG, $people->first()->id]))->assertNotFound();
});
