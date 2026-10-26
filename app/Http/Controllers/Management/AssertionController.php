<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Assertion;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Services\Genealogy\GenealogyAssertionService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssertionController extends Controller
{
    public function __construct(private readonly GenealogyAssertionService $assertions) {}

    public function store(Request $request, string $family, int $person): RedirectResponse
    {
        [$familyModel, $tree] = $this->context($family);
        $this->assertions->record($tree, $request->user(), $tree->people()->findOrFail($person), $request->only(['fact_type', 'value', 'related_person_id', 'relation', 'verification_status', 'notes']));

        return back()->with('status', 'سُجلت الرواية دون تغيير السجل المعتمد. أضف دليلها ثم اقترح اعتمادها.');
    }

    public function update(Request $request, string $family, int $assertion): RedirectResponse
    {
        [, $tree] = $this->context($family);
        $this->assertions->classify($this->assertion($tree, $assertion), $request->user(), $request->only(['relation', 'verification_status']));

        return back()->with('status', 'حُدّث تصنيف الرواية.');
    }

    public function cite(Request $request, string $family, int $assertion): RedirectResponse
    {
        [$familyModel, $tree] = $this->context($family);
        $data = $request->validate([
            'source_id' => ['required', 'integer'], 'locator' => ['nullable', 'string', 'max:255'], 'url' => ['nullable', 'url:http,https', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'], 'verification_status' => ['required', 'in:verified,sourced,pending_review,unresolved'],
        ]);
        $source = $familyModel->sources()->findOrFail($data['source_id']);
        unset($data['source_id']);
        $this->assertions->cite($this->assertion($tree, $assertion), $request->user(), $source, $data);

        return back()->with('status', 'أُرفق الدليل بالرواية.');
    }

    public function propose(Request $request, string $family, int $assertion): RedirectResponse
    {
        [$familyModel, $tree] = $this->context($family);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $change = $this->assertions->propose($this->assertion($tree, $assertion), $request->user(), $reason);

        return redirect()->route('management.changes.show', [$familyModel->slug, $change->id, 'tree' => $tree->slug])->with('status', 'أُرسل اقتراح اعتماد الرواية للمراجعة.');
    }

    public function decline(Request $request, string $family, int $assertion): RedirectResponse
    {
        [, $tree] = $this->context($family);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => 'اذكر سبب رفض الرواية.'])['reason'];
        $this->assertions->decline($this->assertion($tree, $assertion), $request->user(), $reason);

        return back()->with('status', 'رُفضت الرواية مع حفظها وأدلتها في السجل.');
    }

    /** @return array{Family, FamilyTree} */
    private function context(string $slug): array
    {
        $family = Family::query()->active()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);

        return [$family, app(SelectedFamilyTree::class)->resolve($family)];
    }

    private function assertion(FamilyTree $tree, int $id): Assertion
    {
        return Assertion::query()->where('family_tree_id', $tree->id)->findOrFail($id);
    }
}
