<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\Source;
use App\Services\ArabicNameNormalizer;
use App\Services\Genealogy\ProgressiveGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GenealogyGraphController extends Controller
{
    public function show(Request $request, string $family, ProgressiveGraphService $graphs): JsonResponse
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $tree = $familyModel->primaryTree()->active()->firstOrFail();
        $private = $request->boolean('private');
        if ($private) {
            Gate::authorize('view', $familyModel);
        }
        $data = $request->validate([
            'root' => ['nullable', 'integer'], 'depth' => ['nullable', 'integer', 'between:1,5'],
            'limit' => ['nullable', 'integer', 'between:10,200'], 'branch' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'in:focus,isolated'],
        ]);
        $branch = null;
        if (! empty($data['branch'])) {
            $branch = $tree->branches()->where('slug', $data['branch'])
                ->when(! $private, fn ($query) => $query->active()->whereIn('verification_status', [Source::VERIFICATION_VERIFIED, Source::VERIFICATION_SOURCED]))
                ->firstOrFail();
        }
        $rootId = (int) ($branch?->root_person_id ?: ($data['root'] ?? $tree->root_person_id));
        abort_if($rootId === 0, 404);

        return response()->json($graphs->build(
            $tree, $rootId, (int) ($data['depth'] ?? 3), (int) ($data['limit'] ?? 120), ! $private,
            $branch, $data['mode'] ?? 'focus',
        ));
    }

    public function search(Request $request, string $family, ArabicNameNormalizer $normalizer): JsonResponse
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $tree = $familyModel->primaryTree()->active()->firstOrFail();
        $private = $request->boolean('private');
        if ($private) {
            Gate::authorize('view', $familyModel);
        }
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $people = $tree->people()->when(! $private, fn ($query) => $query->publiclyVisible())
            ->where('name_search', 'like', '%'.$normalizer->normalize($data['q']).'%')
            ->orderBy('full_name_ar')->limit(20)->get(['id', 'full_name_ar', 'verification_status']);

        return response()->json(['data' => $people->map(fn ($person) => [
            'id' => $person->id, 'text' => $person->full_name_ar, 'verification' => $person->verification_status,
        ])]);
    }
}
