<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Services\ChangeReviewService;
use App\Services\Genealogy\GenealogyMutationService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ChangeRequestController extends Controller
{
    public function __construct(private SelectedFamilyTree $selection, private ChangeReviewService $reviews, private GenealogyMutationService $mutations) {}

    public function context(string $slug): array
    {
        $family = Family::query()->active()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);

        return [$family, $this->selection->resolve($family)];
    }

    public function index(Request $request, string $family): View
    {
        [$family,$tree] = $this->context($family);
        $status = $request->validate(['status' => ['nullable', 'in:pending,accepted,rejected']])['status'] ?? 'pending';
        $changes = ChangeRequest::query()->where('family_tree_id', $tree->id)->where('status', $status)->orderByDesc('id')->paginate(20)->withQueryString();

        return view('management.changes.index', compact('family', 'tree', 'changes', 'status'));
    }

    public function create(Request $request, string $family): View
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('propose', $family);
        $data = $request->validate(['kind' => ['nullable', 'in:person,relationship,branch'], 'target' => ['nullable', 'integer', 'min:1']]);
        $kind = $data['kind'] ?? 'person';
        $record = isset($data['target']) ? $this->mutations->target($tree, $kind, $data['target']) : null;

        return view('management.changes.create', compact('family', 'tree', 'kind', 'record'));
    }

    public function targets(Request $request, string $family): JsonResponse
    {
        [$family,$tree] = $this->context($family);
        $data = $request->validate(['kind' => ['required', 'in:person,relationship,branch'], 'q' => ['nullable', 'string', 'max:255']]);
        $query = $this->mutations->modelClass($data['kind'])::query()->where('family_tree_id', $tree->id)->orderBy('id');
        if ($data['kind'] === 'relationship') {
            $query->with(['person', 'relatedPerson']);
        } elseif ($data['q'] ?? null) {
            $query->where($data['kind'] === 'person' ? 'full_name_ar' : 'name_ar', 'like', '%'.$data['q'].'%');
        }
        $page = $query->paginate(20);

        return response()->json(['data' => $page->map(fn ($r) => ['id' => $r->id, 'text' => match ($data['kind']) {
            'person' => $r->display_name,'branch' => $r->name_ar,'relationship' => $r->person->display_name.' ← '.$r->relatedPerson->display_name
        }]), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]);
    }

    public function store(Request $request, string $family): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('propose', $family);
        $data = $request->validate(['kind' => ['required', 'in:person,relationship,branch'], 'operation' => ['required', 'in:create,update'], 'target_id' => ['required_if:operation,update', 'nullable', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        $change = $this->reviews->submit($tree, $request->user(), $data['kind'], $data['operation'], $data['target_id'] ?? null, $request->except(['kind', 'operation', 'target_id', 'reason', '_token']), $data['reason']);

        return redirect()->route('management.changes.show', [$family->slug, $change->id, 'tree' => $tree->slug])->with('status', 'أُرسل المقترح للمراجعة دون تعديل البيانات المعتمدة.');
    }

    public function show(string $family, int $change): View
    {
        [$family,$tree] = $this->context($family);
        $change = ChangeRequest::query()->where('family_tree_id', $tree->id)->findOrFail($change);

        $referenceLabels = $this->mutations->referenceLabels($tree, $change->base ?? [], $change->payload);

        return view('management.changes.show', compact('family', 'tree', 'change', 'referenceLabels'));
    }

    public function decide(Request $request, string $family, int $change): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('review', $family);
        $change = ChangeRequest::query()->where('family_tree_id', $tree->id)->findOrFail($change);
        $data = $request->validate(['decision' => ['required', 'in:accepted,rejected'], 'decision_reason' => ['required', 'string', 'max:2000']]);
        $this->reviews->decide($tree, $change, $request->user(), $data['decision'], $data['decision_reason']);

        return back()->with('status', 'حُفظ قرار المراجعة وسجل التدقيق.');
    }
}
