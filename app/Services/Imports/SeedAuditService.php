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
use Database\Seeders\BranchSeeder;
use Database\Seeders\FamilySeeder;
use Database\Seeders\FamilyTreeSeeder;
use Database\Seeders\GenealogyPeopleSeeder;
use Database\Seeders\SourceSeeder;
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

    public function __construct(
        private readonly AlZoubiDataset $dataset,
        private readonly BranchFounderResolutionImporter $founders,
        private readonly AlQawasmehBranchImporter $qawasmeh,
    ) {}

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
        [$conflicts, $preserved, $expected] = $isCurated && $family
            ? $this->compareWithDatasets($family, $tree, $byKey, $edges)
            : [[], [], ['ready_root' => 0, 'ready_root_present' => 0, 'founder_path' => 0, 'founder_path_present' => 0, 'qawasmeh' => 0, 'qawasmeh_present' => 0, 'root_variant_claims' => 0]];
        $integrity = $this->integrity($edges);
        $duplicates = $this->duplicates($people, $edges);
        $privacy = $this->privacy($tree, $people);
        $hasConflicts = $conflicts !== [] || $missing !== [] || array_filter($integrity) !== [];
        $hasWarnings = $preserved !== [] || $duplicates !== [] || $privacy['webroot_datasets'] !== [];

        return [
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
    private function compareWithDatasets(Family $family, ?FamilyTree $tree, Collection $byKey, Collection $edges): array
    {
        $conflicts = [];
        $preserved = [];
        $differs = function (string $record, string $key, array $current, array $seed) use (&$preserved): void {
            foreach ($seed as $field => $value) {
                $now = $current[$field] ?? null;
                if ($this->text($now) !== $this->text($value)) {
                    $preserved[] = ['record' => $record, 'key' => $key, 'field' => $field, 'current' => $this->text($now), 'seed' => $this->text($value)];
                }
            }
        };
        $differs('family', $family->slug, $family->only(array_keys(FamilySeeder::attributes())), FamilySeeder::attributes());
        if ($tree) {
            $differs('tree', $tree->slug, $tree->only(['name_ar', 'name_en', 'description', 'is_active']), collect(FamilyTreeSeeder::attributes())->except('is_primary')->all());
        }
        if ($source = $family->sources()->where('reference', SourceSeeder::DATASET_REFERENCE)->first()) {
            $differs('source', SourceSeeder::DATASET_REFERENCE, $source->only(array_keys(SourceSeeder::attributes())), SourceSeeder::attributes());
        }
        $fathers = $edges->groupBy('person_id');
        $keyOf = $byKey->mapWithKeys(fn (Person $person) => [$person->id => $person->canonical_key]);
        $checkFather = function (string $key, ?string $fatherKey) use ($byKey, $fathers, $keyOf, &$conflicts): void {
            $person = $byKey->get($key);
            if (! $person || $fatherKey === null) {
                return;
            }
            foreach ($fathers->get($person->id, collect()) as $edge) {
                $existing = $keyOf->get($edge->related_person_id) ?? '#'.$edge->related_person_id;
                if ($existing !== $fatherKey) {
                    $conflicts[] = ['key' => $key, 'type' => 'father', 'existing' => $existing, 'expected' => $fatherKey];
                }
            }
        };

        $rows = $this->dataset->readyRootRows();
        foreach ($rows as $row) {
            $person = $byKey->get($row['person_key']);
            if ($person && ! GenealogyPeopleSeeder::matchesDataset($person, $row['name_ar'])) {
                $conflicts[] = ['key' => $row['person_key'], 'type' => 'name', 'existing' => $person->full_name_ar, 'expected' => $row['name_ar']];
            }
            if ($person) {
                $seed = GenealogyPeopleSeeder::attributesFor($row);
                $differs('person', $row['person_key'], $person->only(['status', 'verification_status', 'notes']), collect($seed)->only(['status', 'verification_status', 'notes'])->all());
            }
            $checkFather($row['person_key'], $row['father_key'] ?? null);
        }
        $path = collect($this->founders->resolutions())->flatMap(fn (array $resolution) => $resolution['path']);
        foreach ($path as $row) {
            $person = $byKey->get($row['canonical_key']);
            if ($person && $person->full_name_ar !== $row['name_ar'] && ! in_array($row['name_ar'], $person->alternate_names ?? [], true)) {
                $conflicts[] = ['key' => $row['canonical_key'], 'type' => 'name', 'existing' => $person->full_name_ar, 'expected' => $row['name_ar']];
            }
            if ($person) {
                $seed = BranchFounderResolutionImporter::personAttributes($row);
                $differs('person', $row['canonical_key'], $person->only(['status', 'verification_status', 'notes']), collect($seed)->only(['status', 'verification_status', 'notes'])->all());
            }
            $checkFather($row['canonical_key'], $row['father_canonical_key']);
        }
        if ($tree) {
            $branches = $tree->branches()->with(['parentBranch', 'rootPerson'])->get()->keyBy('slug');
            foreach (BranchSeeder::definitions() as $slug => $definition) {
                if ($branch = $branches->get($slug)) {
                    $differs('branch', $slug, ['name_ar' => $branch->name_ar, 'parent' => $branch->parentBranch?->slug, 'sort_order' => $branch->sort_order], $definition);
                }
            }
            foreach ($this->founders->resolutions() as $resolution) {
                $branch = $branches->get($resolution['branch_slug']);
                if ($branch?->root_person_id !== null && $branch->rootPerson?->canonical_key !== $resolution['founder_canonical_key']) {
                    $preserved[] = ['record' => 'branch', 'key' => $resolution['branch_slug'], 'field' => 'founder', 'current' => $branch->rootPerson?->canonical_key ?? '#'.$branch->root_person_id, 'seed' => $resolution['founder_canonical_key']];
                }
            }
        }
        $manifest = $this->qawasmeh->manifest();
        $qawasmehKeys = array_column($manifest['people'], 'key');

        return [$conflicts, $preserved, [
            'ready_root' => count($rows),
            'ready_root_present' => $byKey->keys()->intersect(array_column($rows, 'person_key'))->count(),
            'founder_path' => $path->count(),
            'founder_path_present' => $byKey->keys()->intersect($path->pluck('canonical_key'))->count(),
            'qawasmeh' => count($qawasmehKeys),
            'qawasmeh_present' => $byKey->keys()->intersect($qawasmehKeys)->count(),
            'root_variant_claims' => $tree ? Assertion::query()->where('family_tree_id', $tree->id)->where('import_key', 'like', 'root-variant:%')->count() : 0,
        ]];
    }

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
        $files = [
            ['Al-Zoubi genealogy dataset', SourceDocuments::path(AlZoubiDataset::PATH), null],
            ['Shared ancestry list', resource_path('data/al-zoubi/shared-ancestry.php'), null],
            ['Branch founder resolution', resource_path('data/al-zoubi/branch-founder-resolution.php'), null],
            ['Branch founder review', resource_path('data/al-zoubi/branch-founder-review.php'), null],
            ['Qawasmeh manifest', resource_path('data/al-qawasmeh/manifest.json'), null],
        ];
        try {
            foreach ($this->qawasmeh->manifest()['sources'] as $row) {
                $files[] = ['Qawasmeh attachment', SourceDocuments::path($row['file']), $row['sha256']];
            }
        } catch (Throwable) {
            // A missing or invalid manifest is already reported by its own entry.
        }

        return array_map(function (array $file): array {
            [$label, $path, $expected] = $file;
            $exists = is_file($path);
            $sha256 = $exists ? hash_file('sha256', $path) : null;

            return [
                'label' => $label,
                'path' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
                'exists' => $exists,
                'bytes' => $exists ? filesize($path) : null,
                'sha256' => $sha256,
                'version' => $label === 'Al-Zoubi genealogy dataset' && $exists ? $this->datasetVersion($path) : null,
                'expected_sha256' => $expected,
                'matches' => $expected === null ? null : $sha256 !== null && hash_equals($expected, $sha256),
            ];
        }, $files);
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
     * Raw genealogy data or source images left in the web root (SD-08). Private sources live in SourceDocuments;
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
