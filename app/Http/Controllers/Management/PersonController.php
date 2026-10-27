<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\ConnectedPersonRequest;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\Source;
use App\Services\AuditLogger;
use App\Services\Genealogy\ConnectedPersonService;
use App\Services\Genealogy\GenealogyDeletionService;
use App\Services\Genealogy\PersonPlacementService;
use App\Services\Genealogy\SelectedFamilyTree;
use App\Services\Genealogy\SharedAncestryScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GenealogyDeletionService $deletion,
    ) {}

    public function show(string $family, int $person): View|RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        if ($survivor = Person::survivorOf($tree->id, $person)) {
            return redirect()->route('management.people.show', [$familyModel->slug, $survivor->id, 'tree' => $tree->slug])->with('status', 'دُمج السجل #'.$person.' في هذا السجل.');
        }
        $personModel = $tree->people()->with(['outgoingRelationships.relatedPerson', 'incomingRelationships.person', 'foundedBranches', 'citations.source', 'outgoingRelationships.citations.source', 'sources'])->whereKey($person)->firstOrFail();
        Gate::authorize('view', $personModel);

        return view('management.people.show', [
            'family' => $familyModel, 'tree' => $tree, 'person' => $personModel,
            'canEdit' => auth()->user()->canEditFamily($familyModel),
            'assertions' => $personModel->assertions()->with(['citations.source', 'relatedPerson', 'creator', 'reviewer', 'changeRequests' => fn ($query) => $query->where('status', 'pending')])->orderBy('fact_type')->orderBy('id')->get()->groupBy('fact_type'),
            'factSources' => Source::query()->where('family_id', $familyModel->id)
                ->when(! auth()->user()->can('manageMembers', $familyModel), fn ($query) => $query->whereDoesntHave('citations', fn ($citations) => $citations->whereNotNull('cross_family_link_id')))
                ->orderBy('title')->limit(200)->get(['id', 'title', 'source_type']),
            'sharedFamilies' => app(SharedAncestryScope::class)->impactedFamilies($personModel),
        ]);
    }

    public function store(ConnectedPersonRequest $request, string $family): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        Gate::authorize('create', [Person::class, $familyModel]);
        $person = app(ConnectedPersonService::class)->save($tree, $request->user(), $request->validated());
        $fatherId = $request->validated('father_id');
        if ($request->boolean('add_another') && $fatherId) {
            // Sequential entry of brothers: back to the same father, nothing to re-select.
            return redirect()->route('management.records.create', [$familyModel->slug, 'people', 'tree' => $tree->slug, 'father_id' => $fatherId])
                ->with('status', 'أُضيف «'.$person->full_name_ar.'» قيد المراجعة. أضف الابن التالي للأب نفسه.');
        }

        return redirect()->route('management.people.show', [$familyModel->slug, $person->id, 'tree' => $tree->slug])->with('status', 'تمت إضافة الشخص بنجاح.');
    }

    /** Possible duplicates before saving (guided form and tree quick add). Read-only. */
    public function candidates(Request $request, string $family): JsonResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        abort_unless($request->user()->can('create', [Person::class, $familyModel]) || $request->user()->can('propose', $familyModel), 403);
        $data = $request->validate(['full_name_ar' => ['nullable', 'string', 'max:255'], 'father_id' => ['nullable', 'integer'], 'exclude' => ['nullable', 'integer']]);
        $father = isset($data['father_id']) ? $tree->people()->findOrFail($data['father_id']) : null;
        $placement = app(PersonPlacementService::class);
        $candidates = collect($placement->candidates($tree, (string) ($data['full_name_ar'] ?? ''), $father?->id, $data['exclude'] ?? null))
            ->map(fn (array $candidate) => $candidate + ['url' => route('management.people.show', [$familyModel->slug, $candidate['id'], 'tree' => $tree->slug])]);

        return response()->json(['data' => $candidates->values(), 'father' => $father ? $placement->fatherContext($tree, $father) : null]);
    }

    /**
     * Quick add of a son from a tree node: only the name is required; the father is the node, validated in this tree.
     * The new record is always pending review and private until reviewed (never published by this path).
     */
    public function quickStore(Request $request, string $family): JsonResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        Gate::authorize('create', [Person::class, $familyModel]);
        $data = $request->validate([
            'father_id' => ['required', 'integer'],
            'full_name_ar' => ['required', 'string', 'max:255'],
            'first_name_ar' => ['nullable', 'string', 'max:255'],
            'living_status' => ['nullable', 'in:living,deceased,unknown'],
            'confirm_distinct' => ['nullable', 'boolean'],
        ], ['full_name_ar.required' => 'أدخل اسم الابن.', 'father_id.required' => 'اختر الأب من الشجرة.']);
        $father = $tree->people()->find($data['father_id']);
        if (! $father) {
            throw ValidationException::withMessages(['father_id' => 'الأب المحدد ليس في هذه الشجرة.']);
        }
        $fullName = trim($data['full_name_ar']);
        try {
            $person = app(ConnectedPersonService::class)->save($tree, $request->user(), [
                'first_name_ar' => trim($data['first_name_ar'] ?? '') ?: explode(' ', $fullName)[0],
                'full_name_ar' => $fullName,
                'status' => ($data['living_status'] ?? null) === 'living' ? Person::STATUS_ACTIVE : Person::STATUS_HISTORICAL,
                'verification_status' => Source::VERIFICATION_PENDING_REVIEW,
                'is_living' => match ($data['living_status'] ?? 'unknown') {
                    'living' => true, 'deceased' => false, default => null
                },
                'father_id' => $father->id,
                'father_verification_status' => Source::VERIFICATION_PENDING_REVIEW,
                'confirm_distinct' => (bool) ($data['confirm_distinct'] ?? false),
            ]);
        } catch (ValidationException $exception) {
            if (! array_key_exists('confirm_distinct', $exception->errors())) {
                throw $exception;
            }

            return response()->json([
                'message' => $exception->errors()['confirm_distinct'][0],
                'errors' => $exception->errors(),
                'candidates' => collect(app(PersonPlacementService::class)->candidates($tree, $fullName, $father->id))
                    ->map(fn (array $candidate) => $candidate + ['url' => route('management.people.show', [$familyModel->slug, $candidate['id'], 'tree' => $tree->slug])])->values(),
            ], 422);
        }

        return response()->json([
            'message' => 'أُضيف «'.$person->full_name_ar.'» ابنًا لـ«'.$father->full_name_ar.'» قيد المراجعة؛ لا يظهر للعامة قبل اعتماده.',
            'person' => [
                'id' => $person->id, 'name' => $person->display_name, 'verification' => $person->verification_status,
                'url' => route('management.people.show', [$familyModel->slug, $person->id, 'tree' => $tree->slug]),
                'edit_url' => route('management.records.edit', [$familyModel->slug, 'people', $person->id, 'tree' => $tree->slug]),
            ],
        ], 201);
    }

    public function update(ConnectedPersonRequest $request, string $family, int $person): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        $personModel = $tree->people()->findOrFail($person);
        Gate::authorize('update', $personModel);
        app(ConnectedPersonService::class)->save($tree, $request->user(), $request->validated(), $personModel);

        return redirect()->route('management.people.show', [$familyModel->slug, $personModel->id, 'tree' => $tree->slug])->with('status', 'تم تحديث بيانات الشخص.');
    }

    public function destroy(string $family, int $person): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        $personModel = $tree->people()->whereKey($person)->firstOrFail();
        Gate::authorize('delete', $personModel);
        $this->deletion->assertPersonCanBeDeleted($personModel);

        DB::transaction(function () use ($familyModel, $personModel): void {
            FamilyTree::query()->whereKey($personModel->family_tree_id)->lockForUpdate()->firstOrFail();
            $personModel->refresh();
            $this->deletion->assertPersonCanBeDeleted($personModel);
            $before = $this->audit->snapshot($personModel);
            $personModel->delete();
            $this->audit->record(auth()->user(), $familyModel, $personModel, 'deleted', $before, null);
        });

        return redirect()->route('management.sections.index', [$familyModel->slug, 'people', 'tree' => request('tree')])->with('status', 'تم حذف الشخص غير المرتبط.');
    }

    /** @return array{Family, FamilyTree} */
    private function familyTree(string $slug): array
    {
        $family = Family::query()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);

        return [$family, app(SelectedFamilyTree::class)->resolve($family)];
    }
}
