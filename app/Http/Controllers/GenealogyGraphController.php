<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Source;
use App\Services\ArabicNameNormalizer;
use App\Services\Genealogy\ProgressiveGraphService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GenealogyGraphController extends Controller
{
    public function show(Request $request, string $family, ProgressiveGraphService $graphs): JsonResponse
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $tree = app(SelectedFamilyTree::class)->resolve($familyModel, true);
        $private = $request->boolean('private');
        if ($private) {
            Gate::authorize('view', $familyModel);
            abort_unless($request->user()?->hasVerifiedEmail(), 403);
        }
        $data = $request->validate([
            'all' => ['nullable', 'boolean'], 'cursor' => ['nullable', 'integer', 'min:0'],
            'root' => ['nullable', 'integer'], 'depth' => ['nullable', 'integer', 'between:1,5'],
            'limit' => ['nullable', 'integer', 'between:10,200'], 'branch' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'in:focus,isolated'], 'after' => ['nullable', 'integer', 'min:0'],
        ]);
        if ($request->boolean('all')) {
            return response()->json($graphs->batch($tree, (int) ($data['cursor'] ?? 0), (int) ($data['limit'] ?? 160), ! $private));
        }
        $branch = null;
        if (! empty($data['branch'])) {
            $branch = $tree->branches()->where('slug', $data['branch'])
                ->when(! $private, fn ($query) => $query->active()->whereIn('verification_status', [Source::VERIFICATION_VERIFIED, Source::VERIFICATION_SOURCED]))
                ->firstOrFail();
        }
        $rootId = (int) ($data['root'] ?? $branch?->root_person_id ?? $tree->root_person_id);
        if ($rootId === 0) {
            return response()->json(['tree' => ['id' => $tree->id, 'name' => $tree->name_ar], 'root_id' => null, 'nodes' => [], 'edges' => [], 'meta' => ['complete' => true, 'returned' => 0]]);
        }

        return response()->json($graphs->build(
            $tree, $rootId, (int) ($data['depth'] ?? 3), (int) ($data['limit'] ?? 120), ! $private,
            $branch, $data['mode'] ?? 'focus', (int) ($data['after'] ?? 0),
        ));
    }

    public function search(Request $request, string $family, ArabicNameNormalizer $normalizer): JsonResponse
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $tree = app(SelectedFamilyTree::class)->resolve($familyModel, true);
        $private = $request->boolean('private');
        if ($private) {
            Gate::authorize('view', $familyModel);
            abort_unless($request->user()?->hasVerifiedEmail(), 403);
        }
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $people = $tree->people()->when(! $private, fn ($query) => $query->treeVisible())
            ->where('name_search', 'like', '%'.$normalizer->normalize($data['q']).'%')
            ->orderBy('full_name_ar')->limit(20)->get(['id', 'full_name_ar', 'title', 'honorific', 'verification_status']);

        $lineage = $this->lineage($tree, $people->modelKeys(), $private);

        return response()->json(['data' => $people->map(fn ($person) => [
            'id' => $person->id, 'text' => $person->display_name, 'lineage' => $lineage[$person->id] ?? null, 'verification' => $person->verification_status,
        ])]);
    }

    /**
     * UC-11: a short "بن father بن grandfather" chain distinguishes repeated names.
     * Public results only name visible ancestors through verified edges.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function lineage(FamilyTree $tree, array $ids, bool $private): array
    {
        $chains = array_fill_keys($ids, []);
        $current = array_combine($ids, $ids);
        for ($generation = 0; $generation < 2 && $current !== []; $generation++) {
            $fathers = $tree->personRelationships()->where('relationship_type', 'father')->where('is_primary', true)
                ->when(! $private, fn ($query) => $query->verified())
                ->whereIn('person_id', array_values($current))->pluck('related_person_id', 'person_id');
            $names = $tree->people()->when(! $private, fn ($query) => $query->treeVisible())->whereKey($fathers->values()->all())->pluck('full_name_ar', 'id');
            $next = [];
            foreach ($current as $origin => $personId) {
                $fatherId = $fathers[$personId] ?? null;
                if ($fatherId && isset($names[$fatherId])) {
                    $chains[$origin][] = 'بن '.$names[$fatherId];
                    $next[$origin] = $fatherId;
                }
            }
            $current = $next;
        }

        return array_map(fn (array $chain) => implode(' ', $chain), array_filter($chains));
    }
}
