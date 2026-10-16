<?php

use App\Models\Branch;
use App\Models\Citation;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Services\Genealogy\GenealogyTraversalService;
use Database\Seeders\AlZoubiGenealogySeeder;
use Database\Seeders\AlZoubiSharedAncestrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('shared ancestry preserves all supplied names order titles honors and pending provenance', function () {
    app(AlZoubiSharedAncestrySeeder::class)->run();
    $names = require resource_path('data/al-zoubi/shared-ancestry.php');
    $tree = Family::query()->where('slug', 'al-zoubi')->sole()->primaryTree;
    $people = $tree->people()->orderBy('canonical_key')->get();
    expect($people->pluck('full_name_ar')->all())->toBe($names)
        ->and($people)->toHaveCount(79)
        ->and($tree->personRelationships()->count())->toBe(78)
        ->and($tree->root_person_id)->toBe($people[0]->id)
        ->and(Branch::query()->count())->toBe(0);
    foreach ($people as $index => $person) {
        expect($person->verification_status)->toBe('pending_review')->and($person->is_living)->toBeFalse();
        if ($index > 0) {
            $edge = $person->outgoingRelationships()->sole();
            expect($edge->related_person_id)->toBe($people[$index - 1]->id)
                ->and($edge->canonical_key)->toBe(AlZoubiSharedAncestrySeeder::edgeKey($index + 1))
                ->and($edge->verification_status)->toBe('pending_review');
        }
    }
    expect($people[0]->full_name_ar)->toBe('آدم')->and($people[0]->honorific)->toBe('عليه السلام')
        ->and($people[48]->full_name_ar)->toBe('علي')->and($people[48]->honorific)->toBe('كرم الله وجهه')
        ->and($people[48]->personAttributes()->count())->toBe(2)
        ->and($people[62]->title)->toBe('اول من لقب بالزعبي والجد الاول لعشيرة الزعبي')
        ->and($people[75]->title)->toBe('جد افخاد الزعبي بالسلط');
    foreach ([0, 6, 9, 12, 18, 19] as $index) {
        expect($people[$index]->personAttributes()->sole()->key)->toBe('prophet');
    }
    expect(Source::query()->sole()->verification_status)->toBe('pending_review')
        ->and(Citation::query()->count())->toBe(157);
    $this->getJson(route('genealogy.search', ['al-zoubi', 'q' => 'آدم']))->assertJsonCount(0, 'data');
});

test('shared seeding is idempotent and preserves manual data reference edits and branches', function () {
    app(AlZoubiSharedAncestrySeeder::class)->run();
    $tree = FamilyTree::query()->sole();
    $person = $tree->people()->where('canonical_key', 'ROOT-046')->sole();
    $person->update(['title' => 'لقب راجعه المستخدم', 'notes' => 'ملاحظة بشرية', 'verification_status' => 'sourced']);
    $manual = $tree->people()->create(['canonical_key' => 'MANUAL-1', 'first_name_ar' => 'شخص يدوي', 'full_name_ar' => 'شخص يدوي']);
    $attribute = PersonAttribute::query()->where('key', 'prophet')->sole();
    $attribute->update(['color' => '#123456', 'is_active' => false]);
    $branch = $tree->branches()->create(['root_person_id' => $person->id, 'name_ar' => 'فرع قائم', 'slug' => 'existing', 'is_active' => false]);
    $counts = [Person::query()->count(), PersonRelationship::query()->count(), DB::table('person_attribute_assignments')->count(), Citation::query()->count()];
    app(AlZoubiSharedAncestrySeeder::class)->run();
    expect([Person::query()->count(), PersonRelationship::query()->count(), DB::table('person_attribute_assignments')->count(), Citation::query()->count()])->toBe($counts)
        ->and($person->fresh()->title)->toBe('لقب راجعه المستخدم')
        ->and($person->fresh()->notes)->toBe('ملاحظة بشرية')
        ->and($person->fresh()->verification_status)->toBe('sourced')
        ->and($attribute->fresh()->color)->toBe('#123456')->and($attribute->fresh()->is_active)->toBeFalse()
        ->and($branch->fresh()->is_active)->toBeFalse()->and($manual->fresh()->full_name_ar)->toBe('شخص يدوي');
});

test('legacy spelling conflicts roll back rather than overwrite or duplicate existing ancestors', function () {
    app(AlZoubiGenealogySeeder::class)->run();
    $before = [Person::query()->orderBy('id')->get()->toJson(), PersonRelationship::query()->orderBy('id')->get()->toJson(), Branch::query()->orderBy('id')->get()->toJson(), Source::query()->count()];
    expect(fn () => app(AlZoubiSharedAncestrySeeder::class)->run())->toThrow(RuntimeException::class, 'requires human reconciliation');
    expect([Person::query()->orderBy('id')->get()->toJson(), PersonRelationship::query()->orderBy('id')->get()->toJson(), Branch::query()->orderBy('id')->get()->toJson(), Source::query()->count()])->toBe($before)
        ->and(PersonAttribute::query()->count())->toBe(0);
});

test('Yusuf remains the unique expansion point and future descendants reuse the existing graph', function () {
    app(AlZoubiSharedAncestrySeeder::class)->run();
    $tree = FamilyTree::query()->sole();
    $yusuf = $tree->people()->where('canonical_key', AlZoubiSharedAncestrySeeder::EXPANSION_KEY)->sole();
    $last = $tree->people()->where('canonical_key', 'ROOT-079')->sole();
    expect($yusuf->full_name_ar)->toBe('يوسف')
        ->and(app(GenealogyTraversalService::class)->getAncestorPath($last->id, null, false)->take(-4)->pluck('full_name_ar')->all())
        ->toBe(['يوسف', 'ذيبان', 'سليمان', 'عبد القادر']);
    $future = $tree->people()->create(['canonical_key' => 'FUTURE-001', 'first_name_ar' => 'امتداد', 'full_name_ar' => 'امتداد']);
    $tree->personRelationships()->create(['person_id' => $future->id, 'related_person_id' => $last->id, 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => 'pending_review']);
    $branch = $tree->branches()->create(['name_ar' => 'فرع اختباري مستقبلي', 'slug' => 'future-test', 'root_person_id' => $future->id, 'is_active' => false]);
    app(AlZoubiSharedAncestrySeeder::class)->run();
    expect($tree->people()->where('full_name_ar', 'يوسف')->count())->toBe(1)
        ->and($tree->people()->count())->toBe(80)->and($branch->fresh()->is_active)->toBeFalse();
});

test('manual conflicting father claims stop seeding without committing any changes', function () {
    app(AlZoubiSharedAncestrySeeder::class)->run();
    $tree = FamilyTree::query()->sole();
    $person = $tree->people()->where('canonical_key', 'ROOT-079')->sole();
    $other = $tree->people()->create(['first_name_ar' => 'يدوي', 'full_name_ar' => 'أب يدوي']);
    $person->outgoingRelationships()->sole()->update(['related_person_id' => $other->id]);
    $count = Person::query()->count();
    expect(fn () => app(AlZoubiSharedAncestrySeeder::class)->run())->toThrow(RuntimeException::class, 'existing father requires review');
    expect(Person::query()->count())->toBe($count)->and($person->outgoingRelationships()->sole()->related_person_id)->toBe($other->id);
});
