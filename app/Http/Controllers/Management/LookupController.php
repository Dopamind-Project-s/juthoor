<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\ArabicNameNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LookupController extends Controller
{
    public function people(Request $request, string $family, ArabicNameNormalizer $normalizer): JsonResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('view', $familyModel);
        $tree = $familyModel->primaryTree()->firstOrFail();
        $query = $tree->people()->orderBy('full_name_ar');
        if ($request->filled('q')) {
            $query->where('name_search', 'like', '%'.$normalizer->normalize((string) $request->query('q')).'%');
        }
        $page = $query->paginate(20, ['id', 'full_name_ar'], 'page');

        return response()->json([
            'data' => collect($page->items())->map(fn ($person) => ['id' => $person->id, 'text' => $person->full_name_ar]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function branches(Request $request, string $family): JsonResponse
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('view', $familyModel);
        $query = $familyModel->primaryTree()->firstOrFail()->branches()->orderBy('name_ar');
        if ($request->filled('q')) {
            $query->where('name_ar', 'like', '%'.$request->query('q').'%');
        }
        $page = $query->paginate(20, ['id', 'name_ar'], 'page');

        return response()->json([
            'data' => collect($page->items())->map(fn ($branch) => ['id' => $branch->id, 'text' => $branch->name_ar]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
