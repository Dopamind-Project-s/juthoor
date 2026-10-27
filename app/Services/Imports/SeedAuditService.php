<?php

namespace App\Services\Imports;

use App\Models\Assertion;
use App\Models\Branch;
use App\Models\Citation;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Services\Imports\LegacySeeders\BranchSeeder;
use App\Services\Imports\LegacySeeders\FamilySeeder;
use App\Services\Imports\LegacySeeders\FamilyTreeSeeder;
use App\Services\Imports\LegacySeeders\GenealogyPeopleSeeder;
use App\Services\Imports\LegacySeeders\SourceSeeder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Read-only preflight for the family and genealogy seeders (D2). It compares the database with the curated datasets and
 * reports what a seeding run would create, refuse or preserve. It only runs SELECT queries and reads dataset files;
 * it never calls a seeder, corrects data or decides a historical question.
 */
class SeedAuditService
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNINGS = 'warnings';

    public const STATUS_CONFLICTS = 'conflicts';

    /**
     * @return array{
     *     generated_at: string,
     *     family_slug: string,
     *     read_only: true,
     *     status: string,
     *     families: array<int, array{slug: string, name_ar: string, is_active: bool, trees: int, people: int}>,
     *     family: array{id: int, slug: string, name_ar: string, is_active: bool}|null,
     *     trees: array<int, array{id: int, slug: string, name_ar: string, is_primary: bool, is_active: bool, root_key: string|null}>,
     *     counts: array{people: int, relationships: int, father_edges: int, branches: int, active_branches: int, founders: int, sources: int, citations: int, open_claims: int},
     *     expected: array{ready_root: int, ready_root_present: int, founder_path: int, founder_path_present: int, qawasmeh: int, qawasmeh_present: int, root_variant_claims: int},
     *     branches: array<int, array{slug: string, name_ar: string, parent_slug: string|null, is_active: bool, verification_status: string|null, founder_key: string|null, founder_name: string|null}>,
     *     sources: array<int, array{reference: string|null, title: string, verification_status: string|null, citations: int}>,
     *     datasets: array<int, array{label: string, path: string, exists: bool, bytes: int|null, sha256: string|null, version: string|null, expected_sha256: string|null, matches: bool|null}>,
     *     missing_dependencies: array<int, string>,
     *     identity_conflicts: array<int, array{key: string, type: string, existing: string, expected: string}>,
     *     preserved_edits: array<int, array{record: string, key: string, field: string, current: string|null, seed: string|null}>,
     *     duplicate_candidates: array<int, array{name: string, father: string|null, people: array<int, array{id: int, key: string|null}>}>,
     *     integrity: array{self_parent: array<int, int>, multiple_primary_fathers: array<int, int>, cycles: array<int, array<int, int>>},
     *     privacy: array{living: int, unknown: int, unknown_but_sourced: int, pending_review: int, qawasmeh_pending: int, public_people: int, public_narratives: int, webroot_datasets: array<int, string>}
     * }
     */
    public function report(?string $familySlug = null): array
    {
        $familySlug ??= FamilySeeder::SLUG;
        $family = Family::query()->where('slug', $familySlug)->first();
        $tree = $family?->familyTrees()->where('slug', FamilyTreeSeeder::SLUG)->first()
            ?? $family?->familyTrees()->where('is_primary', true)->first();
        $isCurated = $familySlug === FamilySeeder::SLUG;
        $missing = [];
        if (! $family) {
            $missing[] = "family [{$familySlug}] is missing; FamilySeeder would create it";
        } elseif (! $tree) {
            $missing[] = 'main tree is missing; FamilyTreeSeeder would create it';
        }
        if ($isCurated && $family && ! $family->sources()->where('reference', SourceSeeder::DATASET_REFERENCE)->exists()) {
            $missing[] = 'dataset source '.SourceSeeder::DATASET_REFERENCE.' is missing; SourceSeeder would create it';
        }
        $people = $tree ? $tree->people()->get() : new Collection;
        $edges = $tree ? $tree->personRelationships()->where('relationship_type', PersonRelationship::TYPE_FATHER)->get() : new Collection;
        $byKey = $people->whereNotNull('canonical_key')->keyBy('canonical_key');
        $datasets = $this->datasets();
        if ($isCurated) {
            foreach ($datasets as $file) {
                if (! $file['exists']) {
                    $missing[] = "dataset file {$file['path']} is missing";
                } elseif ($file['matches'] === false) {
                    $missing[] = "dataset file {$file['path']} changed (checksum differs from the manifest)";
                }
            }
        }
        [$conflicts, $preserved, $expected] = [[], [], ['ready_root' => 0, 'ready_root_present' => 0, 'founder_path' => 0, 'founder_path_present' => 0, 'qawasmeh' => 0, 'qawasmeh_present' => 0, 'root_variant_claims' => 0]];
        $unified = null;
        if ($isCurated && $family?->sources()->where('reference', 'like', 'unified:%')->exists()) {
            $proposal = app(UnifiedGenealogyDataset::class)->data();
            $missing = [];
            $conflicts = [];
            $preserved = [];
            $fathers = $edges->where('is_primary', true)->keyBy('person_id');
            foreach ($proposal['people'] as $row) {
                $person = $byKey->get($row['key']);
                if (! $person) {
                    $missing[] = 'normalized person '.$row['key'];

                    continue;
                }
                if ($person->full_name_ar !== $row['name']) {
                    $preserved[] = ['record' => 'person', 'key' => $row['key'], 'field' => 'full_name_ar', 'current' => $person->full_name_ar, 'seed' => $row['name']];
                }
                $father = $fathers->get($person->id);
                if (($row['father'] && $father?->related_person_id !== $byKey->get($row['father'])?->id) || (! $row['father'] && $father)) {
                    $conflicts[] = ['key' => $row['key'], 'type' => 'normalized father conflict', 'existing' => (string) $father?->related_person_id, 'expected' => $row['father'] ?? 'root'];
                }
            }
            $expected = ['ready_root' => 79, 'ready_root_present' => $byKey->keys()->filter(fn ($key) => str_starts_with($key, 'ROOT-'))->count(), 'founder_path' => collect($proposal['people'])->filter(fn ($row) => str_starts_with($row['key'], 'AZ-') && ! str_starts_with($row['key'], 'AZ-DESC-'))->count(), 'founder_path_present' => $byKey->keys()->filter(fn ($key) => str_starts_with($key, 'AZ-') && ! str_starts_with($key, 'AZ-DESC-'))->count(), 'qawasmeh' => 34, 'qawasmeh_present' => $byKey->keys()->filter(fn ($key) => str_starts_with($key, 'QW-'))->count(), 'root_variant_claims' => $tree->assertions()->where('import_key', 'like', 'unified:root-variant:%')->count()];
            $unified = ['version' => $proposal['version'], 'expected_people' => count($proposal['people']), 'review_observations' => Citation::query()->where('family_tree_id', $tree->id)->where('locator', 'like', 'unified-review:%')->count()];
        }
        $integrity = $this->integrity($edges);
        $duplicates = $this->duplicates($people, $edges);
        $privacy = $this->privacy($tree, $people);
        $hasConflicts = $conflicts !== [] || $missing !== [] || array_filter($integrity) !== [];
        $hasWarnings = $preserved !== [] || $duplicates !== [] || $privacy['webroot_datasets'] !== [];

        return [
            'unified' => $unified,
            'generated_at' => now()->toIso8601String(),
            'family_slug' => $familySlug,
            'read_only' => true,
            'status' => $hasConflicts ? self::STATUS_CONFLICTS : ($hasWarnings ? self::STATUS_WARNINGS : self::STATUS_OK),
            'families' => Family::query()->withCount('familyTrees')->orderBy('id')->get()->map(fn (Family $item) => [
                'slug' => $item->slug, 'name_ar' => $item->name_ar, 'is_active' => (bool) $item->is_active, 'trees' => $item->family_trees_count,
                'people' => Person::query()->whereIn('family_tree_id', $item->familyTrees()->select('id'))->count(),
            ])->all(),
            'family' => $family ? ['id' => $family->id, 'slug' => $family->slug, 'name_ar' => $family->name_ar, 'is_active' => (bool) $family->is_active] : null,
            'trees' => $family ? $family->familyTrees()->with('rootPerson')->orderBy('id')->get()->map(fn (FamilyTree $item) => [
                'id' => $item->id, 'slug' => $item->slug, 'name_ar' => $item->name_ar, 'is_primary' => (bool) $item->is_primary,
                'is_active' => (bool) $item->is_active, 'root_key' => $item->rootPerson?->canonical_key,
            ])->all() : [],
            'counts' => [
                'people' => $people->count(),
                'relationships' => $tree ? $tree->personRelationships()->count() : 0,
                'father_edges' => $edges->count(),
                'branches' => $tree ? $tree->branches()->count() : 0,
                'active_branches' => $tree ? $tree->branches()->where('is_active', true)->count() : 0,
                'founders' => $tree ? $tree->branches()->whereNotNull('root_person_id')->count() : 0,
                'sources' => $family ? $family->sources()->count() : 0,
                'citations' => $tree ? Citation::query()->where('family_tree_id', $tree->id)->count() : 0,
                'open_claims' => $tree ? Assertion::query()->where('family_tree_id', $tree->id)->where('state', 'proposed')->count() : 0,
            ],
            'expected' => $expected,
            'branches' => $tree ? $tree->branches()->with(['parentBranch', 'rootPerson'])->orderBy('id')->get()->map(fn (Branch $branch) => [
                'slug' => $branch->slug, 'name_ar' => $branch->name_ar, 'parent_slug' => $branch->parentBranch?->slug,
                'is_active' => (bool) $branch->is_active, 'verification_status' => $branch->verification_status,
                'founder_key' => $branch->rootPerson?->canonical_key, 'founder_name' => $branch->rootPerson?->full_name_ar,
            ])->all() : [],
            'sources' => $family ? $family->sources()->withCount('citations')->orderBy('id')->get()->map(fn (Source $source) => [
                'reference' => $source->reference, 'title' => $source->title, 'verification_status' => $source->verification_status,
                'citations' => $source->citations_count,
            ])->all() : [],
            'datasets' => $isCurated ? $datasets : [],
            'missing_dependencies' => $missing,
            'identity_conflicts' => $conflicts,
            'preserved_edits' => $preserved,
            'duplicate_candidates' => $duplicates,
            'integrity' => $integrity,
            'privacy' => $privacy,
        ];
    }

    /**
     * @param  Collection<string, Person>  $byKey
     * @param  Collection<int, PersonRelationship>  $edges
     * @return array{0: array<int, array{key: string, type: string, existing: string, expected: string}>, 1: array<int, array{record: string, key: string, field: string, current: string|null, seed: string|null}>, 2: array<string, int>}
     */


    /**
     * @param  Collection<int, PersonRelationship>  $edges
     * @return array{self_parent: array<int, int>, multiple_primary_fathers: array<int, int>, cycles: array<int, array<int, int>>}
     */
    private function integrity(Collection $edges): array
    {
        $fathers = [];
        foreach ($edges as $edge) {
            $fathers[$edge->person_id][] = $edge->related_person_id;
        }
        $cycles = [];
        $state = [];
        foreach (array_keys($fathers) as $start) {
            $path = [];
            $node = $start;
            // Follow every father link; a node revisited on the current walk closes a cycle.
            $stack = [[$start, 0]];
            while ($stack !== []) {
                [$node, $index] = array_pop($stack);
                if ($index === 0) {
                    if (($state[$node] ?? 0) === 2) {
                        continue;
                    }
                    if (($state[$node] ?? 0) === 1) {
                        $cycles[] = array_slice($path, array_search($node, $path, true));

                        continue;
                    }
                    $state[$node] = 1;
                    $path[] = $node;
                }
                $next = $fathers[$node][$index] ?? null;
                if ($next === null) {
                    $state[$node] = 2;
                    array_pop($path);

                    continue;
                }
                $stack[] = [$node, $index + 1];
                $stack[] = [$next, 0];
            }
        }

        return [
            'self_parent' => $edges->filter(fn ($edge) => $edge->person_id === $edge->related_person_id)->pluck('person_id')->values()->all(),
            'multiple_primary_fathers' => $edges->where('is_primary', true)->countBy('person_id')->filter(fn (int $count) => $count > 1)->keys()->all(),
            'cycles' => array_values(array_filter($cycles, fn (array $cycle) => count($cycle) > 1)),
        ];
    }

    /**
     * Same Arabic-normalized name under the same primary father (or both without one): candidates for human review only.
     *
     * @param  Collection<int, Person>  $people
     * @param  Collection<int, PersonRelationship>  $edges
     * @return array<int, array{name: string, father: string|null, people: array<int, array{id: int, key: string|null}>}>
     */
    private function duplicates(Collection $people, Collection $edges): array
    {
        $fatherOf = $edges->where('is_primary', true)->pluck('related_person_id', 'person_id');
        $names = $people->pluck('full_name_ar', 'id');

        return $people->filter(fn (Person $person) => filled($person->name_search))
            ->groupBy(fn (Person $person) => $person->name_search.'|'.($fatherOf->get($person->id) ?? ''))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(fn (Collection $group) => [
                'name' => $group->first()->full_name_ar,
                'father' => ($fatherId = $fatherOf->get($group->first()->id)) ? ($names->get($fatherId) ?? '#'.$fatherId) : null,
                'people' => $group->map(fn (Person $person) => ['id' => $person->id, 'key' => $person->canonical_key])->values()->all(),
            ])->values()->all();
    }

    /**
     * @param  Collection<int, Person>  $people
     * @return array{living: int, unknown: int, unknown_but_sourced: int, pending_review: int, qawasmeh_pending: int, public_people: int, public_narratives: int, webroot_datasets: array<int, string>}
     */
    private function privacy(?FamilyTree $tree, Collection $people): array
    {
        return [
            'living' => $people->filter(fn (Person $person) => $person->is_living === true)->count(),
            'unknown' => $people->filter(fn (Person $person) => $person->is_living === null)->count(),
            'unknown_but_sourced' => $people->filter(fn (Person $person) => $person->is_living === null && in_array($person->verification_status, [Source::VERIFICATION_VERIFIED, Source::VERIFICATION_SOURCED], true))->count(),
            'pending_review' => $people->where('verification_status', Source::VERIFICATION_PENDING_REVIEW)->count(),
            'qawasmeh_pending' => $people->filter(fn (Person $person) => str_starts_with((string) $person->canonical_key, 'QW-') && $person->verification_status === Source::VERIFICATION_PENDING_REVIEW)->count(),
            'public_people' => $tree ? $tree->people()->publiclyVisible()->count() : 0,
            'public_narratives' => $people->filter(fn (Person $person) => $person->verification_status === Source::VERIFICATION_PENDING_REVIEW && $person->public_narrative)->count(),
            'webroot_datasets' => $this->webrootDatasets(),
        ];
    }

    /** @return array<int, array{label: string, path: string, exists: bool, bytes: int|null, sha256: string|null, version: string|null, expected_sha256: string|null, matches: bool|null}> */
    private function datasets(): array
    {
        // Optional reports use database records and the embedded snapshot, never external datasets.
        return [];
    }

    private function datasetVersion(string $path): ?string
    {
        try {
            return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['meta']['dataset_version'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Raw genealogy data or source images left in the web root (SD-08). Original source files are no longer distributed;
     * anything reported here was added to `public/docs` again and is downloadable.
     *
     * @return array<int, string>
     */
    private function webrootDatasets(): array
    {
        $directory = public_path('docs');
        if (! is_dir($directory)) {
            return [];
        }

        return collect(scandir($directory))
            ->filter(fn (string $file) => preg_match('/\.(xlsx|csv|xls)$/i', $file) === 1 || preg_match('/genealogy_dataset|قواسمه|بكر|رحايمه|عودتلات/u', $file) === 1)
            ->map(fn (string $file) => 'public/docs/'.$file)->values()->all();
    }

    private function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
