<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\PersonMerge;
use App\Services\Genealogy\MergeCandidateFinder;
use App\Services\Genealogy\PersonMergeService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Members may review possible duplicates; only family administrators preview, merge and undo (super admins for shared ancestry). */
class PersonMergeController extends Controller
{
    public function __construct(private readonly PersonMergeService $merges) {}

    public function index(Request $request, string $family, MergeCandidateFinder $finder): View
    {
        [$familyModel, $tree] = $this->context($family);

        return view('management.merges.index', [
            'family' => $familyModel, 'tree' => $tree,
            'candidates' => $finder->candidates($tree, $request->user()),
            'history' => PersonMerge::query()->where('family_tree_id', $tree->id)->with(['survivor', 'merged', 'performer'])->latest('id')->limit(30)->get(),
            'canMerge' => $request->user()->can('publish', $familyModel),
        ]);
    }

    public function preview(Request $request, string $family): View
    {
        [$familyModel, $tree] = $this->context($family);
        Gate::authorize('publish', $familyModel);
        $data = $request->validate(['survivor' => ['required', 'integer'], 'merged' => ['required', 'integer'], 'decisions' => ['sometimes', 'array'], 'decisions.*' => ['in:survivor,merged']]);

        return view('management.merges.preview', [
            'family' => $familyModel, 'tree' => $tree,
            'plan' => $this->merges->plan($tree, (int) $data['survivor'], (int) $data['merged'], $data['decisions'] ?? []),
        ]);
    }

    public function store(Request $request, string $family): RedirectResponse
    {
        [$familyModel, $tree] = $this->context($family);
        Gate::authorize('publish', $familyModel);
        $data = $request->validate([
            'survivor' => ['required', 'integer'], 'merged' => ['required', 'integer'], 'decisions' => ['sometimes', 'array'], 'decisions.*' => ['in:survivor,merged'],
            'fingerprint' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'أكّد أنك راجعت المعاينة قبل الدمج.']);
        $operation = $this->merges->merge($tree, $request->user(), (int) $data['survivor'], (int) $data['merged'], $data['decisions'] ?? [], $data['fingerprint'], $data['reason']);

        return redirect()->route('management.merges.show', [$familyModel->slug, $operation->id, 'tree' => $tree->slug])->with('status', 'تم الدمج وحُفظ سجل العملية القابل للتراجع.');
    }

    public function show(Request $request, string $family, int $merge): View
    {
        [$familyModel, $tree] = $this->context($family);
        Gate::authorize('publish', $familyModel);
        $operation = PersonMerge::query()->where('family_tree_id', $tree->id)->with(['survivor', 'merged', 'performer', 'undoer'])->findOrFail($merge);

        return view('management.merges.show', ['family' => $familyModel, 'tree' => $tree, 'operation' => $operation, 'blockers' => $this->merges->undoBlockers($operation)]);
    }

    public function undo(Request $request, string $family, int $merge): RedirectResponse
    {
        [$familyModel, $tree] = $this->context($family);
        Gate::authorize('publish', $familyModel);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->merges->undo(PersonMerge::query()->where('family_tree_id', $tree->id)->findOrFail($merge), $request->user(), $reason);

        return back()->with('status', 'تم التراجع عن الدمج واستعادة السجلين.');
    }

    /** @return array{Family, FamilyTree} */
    private function context(string $slug): array
    {
        $family = Family::query()->active()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);

        return [$family, app(SelectedFamilyTree::class)->resolve($family)];
    }
}
