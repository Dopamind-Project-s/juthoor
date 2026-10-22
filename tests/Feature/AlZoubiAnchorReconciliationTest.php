<?php

use App\Models\AuditLog;
use App\Models\Citation;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Services\Genealogy\DuplicatePersonDetector;
use App\Services\Imports\AlQawasmehBranchImporter;
use Database\Seeders\AlQawasmehBranchSeeder;
use Database\Seeders\AlZoubiSharedAncestrySeeder;
use Database\Seeders\FamilySeeder;
use Database\Seeders\FamilyTreeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array<string, int> */
function anchorCounts(): array
{
    return ['people' => Person::query()->count(), 'relationships' => PersonRelationship::query()->count(), 'citations' => Citation::query()->count(), 'audits' => AuditLog::query()->count()];
}

test('the base seed followed by the Qawasmeh seeder reconciles ROOT-077 in place without duplicating people', function () {
    $this->seed();
    $root77 = Person::query()->where('canonical_key', 'ROOT-077')->sole();
    expect($root77->full_name_ar)->toBe('ذبيان');

    $this->seed(AlQawasmehBranchSeeder::class);

    $reconciled = $root77->fresh();
    $sulayman = Person::query()->where('canonical_key', 'AZ-RAHAYMEH-SULAYMAN')->sole();
    expect($reconciled->full_name_ar)->toBe('ذيبان')
        ->and($reconciled->first_name_ar)->toBe('ذيبان')
        ->and($reconciled->alternate_names)->toBe(['ذبيان'])
        ->and($reconciled->verification_status)->toBe($root77->verification_status)
        ->and(Person::query()->where('canonical_key', 'ROOT-078')->exists())->toBeFalse()
        ->and($root77->incomingRelationships()->whereHas('person', fn ($person) => $person->where('full_name_ar', 'سليمان'))->count())->toBe(1)
        ->and(Person::query()->where('canonical_key', 'QW-0001')->sole()->outgoingRelationships()->sole()->related_person_id)->toBe($sulayman->id)
        ->and(Citation::query()->where('locator', 'anchor:ROOT-078')->where('person_id', $sulayman->id)->where('verification_status', 'pending_review')->exists())->toBeTrue();

    $audit = AuditLog::query()->where('action', 'spelling_reconciled')->sole();
    expect($audit->auditable_id)->toBe($root77->id)
        ->and($audit->before['full_name_ar'])->toBe('ذبيان')
        ->and($audit->after['full_name_ar'])->toBe('ذيبان')
        ->and($audit->reason)->toBe(AlQawasmehBranchImporter::SPELLING_DECISION);
});

test('repeating both seeders keeps person relationship citation and audit counts identical', function () {
    $this->seed();
    $this->seed(AlQawasmehBranchSeeder::class);
    $before = anchorCounts();

    $this->seed();
    $this->seed(AlQawasmehBranchSeeder::class);

    expect(anchorCounts())->toBe($before)
        ->and(Person::query()->where('canonical_key', 'ROOT-077')->value('full_name_ar'))->toBe('ذيبان');
});

test('the recorded variant spelling stays searchable in the public tree', function () {
    $this->seed();
    $this->seed(AlQawasmehBranchSeeder::class);
    $root77 = Person::query()->where('canonical_key', 'ROOT-077')->sole();

    $this->getJson(route('genealogy.search', ['al-zoubi', 'q' => 'ذبيان']))->assertOk()->assertJsonFragment(['id' => $root77->id, 'text' => 'ذيبان']);
    $this->get(route('genealogy.person', ['al-zoubi', $root77->id]))->assertOk()->assertSee('ذبيان');
});

test('the base seed refuses to overwrite unreconciled names and writes nothing', function () {
    $this->seed();
    Person::query()->where('canonical_key', 'ROOT-010')->update(['full_name_ar' => 'اسم معدل يدويا']);
    $before = anchorCounts();

    expect(fn () => $this->seed())->toThrow(RuntimeException::class, 'ROOT-010: existing [اسم معدل يدويا]');
    expect(anchorCounts())->toBe($before)
        ->and(Person::query()->where('canonical_key', 'ROOT-010')->value('full_name_ar'))->toBe('اسم معدل يدويا');
});

test('the shared ancestry path keeps its own anchors and the base seed cannot silently rewrite it', function () {
    foreach ([FamilySeeder::class, FamilyTreeSeeder::class, AlZoubiSharedAncestrySeeder::class, AlQawasmehBranchSeeder::class] as $seeder) {
        $this->seed($seeder);
    }
    $before = anchorCounts();

    expect(Person::query()->where('canonical_key', 'QW-0001')->sole()->outgoingRelationships()->sole()->relatedPerson->canonical_key)->toBe('ROOT-078')
        ->and(AuditLog::query()->where('action', 'spelling_reconciled')->exists())->toBeFalse();
    expect(fn () => $this->seed())->toThrow(RuntimeException::class);
    expect(anchorCounts())->toBe($before);
});

test('the Qawasmeh seeder explains its prerequisite on an empty database', function () {
    expect(fn () => $this->seed(AlQawasmehBranchSeeder::class))->toThrow(DomainException::class, 'seed the Al-Zoubi family first');
});

test('seeded people get Arabic search keys so public search and duplicate detection see them', function () {
    $this->seed();
    $noah = Person::query()->where('canonical_key', 'ROOT-010')->sole();

    expect(Person::query()->whereNull('name_search')->count())->toBe(0)
        ->and(app(DuplicatePersonDetector::class)->matches($noah->familyTree, $noah->full_name_ar, $noah->outgoingRelationships()->sole()->related_person_id)->modelKeys())->toBe([$noah->id]);
    $this->getJson(route('genealogy.search', ['al-zoubi', 'q' => 'نوح']))->assertOk()->assertJsonFragment(['id' => $noah->id]);
});
