<?php

use App\Models\Assertion;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Citation;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\Source;
use Database\Seeders\AlQawasmehBranchSeeder;
use Database\Seeders\AlZoubiGenealogySeeder;
use Database\Seeders\AlZoubiRootVariantSeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array<string, int> */
function seederSafetyCounts(): array
{
    return [
        'families' => Family::query()->count(), 'trees' => FamilyTree::query()->count(),
        'people' => Person::query()->count(), 'relationships' => PersonRelationship::query()->count(),
        'branches' => Branch::query()->count(), 'founders' => Branch::query()->whereNotNull('root_person_id')->count(),
        'sources' => Source::query()->count(), 'citations' => Citation::query()->count(), 'assertions' => Assertion::query()->count(),
        'attributes' => PersonAttribute::query()->count(), 'audits' => AuditLog::query()->count(),
        'person_sources' => DB::table('person_sources')->count(),
    ];
}

test('db:seed twice keeps identical counts with and without the Qawasmeh import', function () {
    $this->seed();
    $base = seederSafetyCounts();
    $this->seed();
    expect(seederSafetyCounts())->toBe($base)
        ->and($base['people'])->toBe(85)
        ->and($base['relationships'])->toBe(84)
        ->and($base['branches'])->toBe(6);

    $this->seed(AlQawasmehBranchSeeder::class);
    $withQawasmeh = seederSafetyCounts();
    $this->seed();
    $this->seed(AlQawasmehBranchSeeder::class);
    expect(seederSafetyCounts())->toBe($withQawasmeh)
        ->and($withQawasmeh['people'])->toBe(119)
        ->and($withQawasmeh['relationships'])->toBe(118)
        ->and(Person::query()->where('canonical_key', 'like', 'QW-%')->where('verification_status', 'pending_review')->count())->toBe(34)
        ->and(Person::query()->where('canonical_key', 'like', 'QW-%')->publiclyVisible()->count())->toBe(0);
});

test('rerunning the seeders preserves administrator edits to family tree people branches founders and colors', function () {
    $this->seed();
    $family = Family::query()->where('slug', 'al-zoubi')->sole();
    $tree = $family->primaryTree;
    $family->update(['name_ar' => 'اسم معدل إداريًا', 'short_description' => 'وصف إداري', 'location' => 'موقع إداري', 'is_active' => false]);
    $tree->update(['is_active' => false, 'description' => 'وصف شجرة إداري', 'name_en' => 'Edited tree']);
    $root = $tree->people()->where('canonical_key', 'ROOT-010')->sole();
    $root->update([
        'verification_status' => Source::VERIFICATION_VERIFIED, 'notes' => 'ملاحظة مراجعة', 'is_living' => null,
        'field_visibility' => ['birth_year' => 'restricted'], 'alternate_names' => ['تهجئة مراجعة'],
    ]);
    $founderPath = $tree->people()->where('canonical_key', 'AZ-BAKR-ALI')->sole();
    $founderPath->update(['verification_status' => Source::VERIFICATION_PENDING_REVIEW, 'notes' => 'قيد مراجعة إدارية']);
    $edge = $root->outgoingRelationships()->sole();
    $edge->update(['verification_status' => Source::VERIFICATION_VERIFIED, 'source_id' => null]);
    $branches = $tree->branches()->get()->keyBy('slug');
    $branches['eid']->update(['name_ar' => 'عيد المعدل', 'parent_branch_id' => null, 'sort_order' => 9]);
    $branches['bakr']->update(['is_active' => false, 'description' => 'وصف فرع إداري', 'verification_status' => Source::VERIFICATION_PENDING_REVIEW]);
    $otherFounder = $tree->people()->where('canonical_key', 'AZ-RAHAYMEH-SULAYMAN')->sole();
    $branches['al-rahaymeh']->update(['root_person_id' => $otherFounder->id]);
    $source = $family->sources()->where('reference', SourceSeeder::DATASET_REFERENCE)->sole();
    $source->update(['title' => 'عنوان مراجع', 'verification_status' => Source::VERIFICATION_VERIFIED]);
    PersonAttribute::query()->where('key', 'prophet')->update(['color' => '#000000', 'label' => 'تسمية مخصصة']);
    $snapshot = fn () => [
        $family->fresh()->getAttributes(), $tree->fresh()->getAttributes(), $root->fresh()->getAttributes(), $founderPath->fresh()->getAttributes(),
        $edge->fresh()->getAttributes(), Branch::query()->orderBy('id')->get()->map->getAttributes()->all(), $source->fresh()->getAttributes(),
        PersonAttribute::query()->where('key', 'prophet')->sole()->getAttributes(),
    ];
    $before = $snapshot();
    $counts = seederSafetyCounts();

    $this->seed();

    expect($snapshot())->toBe($before)
        ->and(seederSafetyCounts())->toBe($counts)
        ->and($root->fresh()->is_living)->toBeNull();
});

test('a seeded person whose father was changed by review is refused without writing anything', function () {
    $this->seed();
    $tree = Family::query()->where('slug', 'al-zoubi')->sole()->primaryTree;
    $person = $tree->people()->where('canonical_key', 'ROOT-010')->sole();
    $other = $tree->people()->where('canonical_key', 'ROOT-005')->sole();
    $person->outgoingRelationships()->sole()->update(['related_person_id' => $other->id]);
    $tree->people()->where('canonical_key', 'ROOT-020')->sole()->outgoingRelationships()->delete();
    $before = seederSafetyCounts();

    expect(fn () => $this->seed())->toThrow(RuntimeException::class, 'ROOT-010: existing father [ROOT-005], dataset [ROOT-009]');
    expect(seederSafetyCounts())->toBe($before)
        ->and($person->outgoingRelationships()->sole()->related_person_id)->toBe($other->id)
        ->and($person->outgoingRelationships()->count())->toBe(1);
});

test('founder path records with a different father or name are refused before any write', function () {
    $this->seed();
    $tree = Family::query()->where('slug', 'al-zoubi')->sole()->primaryTree;
    $muhammad = $tree->people()->where('canonical_key', 'AZ-BAKR-MUHAMMAD')->sole();
    $muhammad->outgoingRelationships()->sole()->update(['related_person_id' => $tree->people()->where('canonical_key', 'ROOT-077')->value('id')]);
    $before = seederSafetyCounts();

    expect(fn () => $this->seed())->toThrow(DomainException::class, 'AZ-BAKR-MUHAMMAD: existing father differs from [AZ-BAKR-ALI]');
    expect(seederSafetyCounts())->toBe($before);
});

test('a missing seeded record is recreated but existing ones are not reset and unknown status is not reclassified', function () {
    $this->seed();
    $tree = Family::query()->where('slug', 'al-zoubi')->sole()->primaryTree;
    $unknown = $tree->people()->where('canonical_key', 'ROOT-030')->sole();
    $unknown->update(['is_living' => null]);
    $this->seed();
    expect($unknown->fresh()->is_living)->toBeNull();

    $tree->branches()->where('slug', 'al-awtalat')->delete();
    $this->seed();
    expect($tree->branches()->where('slug', 'al-awtalat')->sole())
        ->name_ar->toBe('العوتلات')
        ->is_active->toBeFalse()
        ->verification_status->toBe(Source::VERIFICATION_PENDING_REVIEW);
});

test('root variants and the ROOT-077 decision stay intact across reseeding', function () {
    $this->seed();
    $this->seed(AlQawasmehBranchSeeder::class);
    $this->seed(AlZoubiRootVariantSeeder::class);
    $claims = Assertion::query()->where('import_key', 'like', 'root-variant:%')->count();
    $root77 = Person::query()->where('canonical_key', 'ROOT-077')->sole()->getAttributes();

    $this->seed();
    $this->seed(AlQawasmehBranchSeeder::class);
    $this->seed(AlZoubiRootVariantSeeder::class);

    expect(Assertion::query()->where('import_key', 'like', 'root-variant:%')->count())->toBe($claims)
        ->and(Person::query()->where('canonical_key', 'ROOT-077')->sole()->getAttributes())->toBe($root77)
        ->and(Person::query()->where('canonical_key', 'ROOT-078')->exists())->toBeFalse();
});

test('the genealogy import classifies the living status of new seeded records', function () {
    $stats = app(AlZoubiGenealogySeeder::class)->import();
    expect($stats['created'])->toBe(169)
        ->and(Person::query()->whereNull('is_living')->count())->toBe(0);
});
