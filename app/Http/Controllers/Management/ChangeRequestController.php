<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Assertion;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Services\ChangeReviewService;
use App\Services\Genealogy\DuplicatePersonDetector;
use App\Services\Genealogy\GenealogyMutationService;
use App\Services\Genealogy\PersonPlacementService;
use App\Services\Genealogy\PersonPrivacy;
use App\Services\Genealogy\PublicationGuard;
use App\Services\Genealogy\SelectedFamilyTree;
use App\Services\Genealogy\SharedAncestryScope;
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
        $filters = $request->validate(['kind' => ['nullable', 'in:person,relationship,branch'], 'q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean']]);
        $changes = ChangeRequest::query()->where('family_tree_id', $tree->id)->where('status', $status)
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('target_type', $kind))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where('reason', 'like', '%'.$term.'%'))
            ->when($request->boolean('mine'), fn ($query) => $query->where('submitted_by', $request->user()->id))
            ->with('submitter:id,name', 'reviewer:id,name')->orderByDesc('id')->paginate(20)->withQueryString();

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
        if ($change->target_type === 'person') {
            $privacy = app(PersonPrivacy::class);
            $current = $change->target_id ? $tree->people()->find($change->target_id) : null;
            // In-memory only: the stored proposal is never altered.
            $change->setAttribute('base', $change->base ? $privacy->redactSnapshot($change->base, $family, auth()->user(), $current) : null);
            $change->setAttribute('payload', $privacy->redactSnapshot($change->payload, $family, auth()->user(), $current));
        }
        $possibleDuplicates = $change->status === 'pending' && $change->target_type === 'person' && $change->operation === 'create'
            ? app(DuplicatePersonDetector::class)->matches($tree, (string) ($change->payload['full_name_ar'] ?? ''), null)
            : collect();

        $claim = $change->assertion()->with(['citations.source', 'person.familyTree.family', 'relatedPerson'])->first();
        $competing = $claim ? Assertion::query()->where('person_id', $claim->person_id)->where('fact_type', $claim->fact_type)->whereKeyNot($claim->id)
            ->with(['citations.source', 'person.familyTree.family', 'relatedPerson'])->orderBy('id')->get() : collect();
        $target = $change->target_id ? $this->mutations->modelClass($change->target_type)::query()->where('family_tree_id', $tree->id)->find($change->target_id) : null;
        $sharedFamilies = $target ? app(SharedAncestryScope::class)->impactedFamilies($target) : collect();

        // Review context: who and when, the people affected, the publication effect and earlier decisions on the same record.
        $change->load(['submitter:id,name', 'reviewer:id,name']);
        $subject = match (true) {
            $target instanceof Person => $target,
            $target instanceof PersonRelationship => $target->person,
            $change->target_type === 'relationship' && isset($change->payload['person_id']) => $tree->people()->find($change->payload['person_id']),
            default => null,
        };
        $guard = app(PublicationGuard::class);
        $candidate = null;
        if ($change->status === 'pending') {
            $candidate = $target ? clone $target : new ($this->mutations->modelClass($change->target_type));
            $candidate->forceFill(array_intersect_key($change->getRawOriginal('payload') ? json_decode($change->getRawOriginal('payload'), true) : [], array_flip($candidate->getFillable())));
        }
        $context = [
            'subject' => $subject,
            'impact' => $subject ? app(PersonPlacementService::class)->impact($tree, $subject) : null,
            'was_public' => $target ? $guard->isPublic($target, $target->getAttributes()) : false,
            'will_be_public' => $candidate ? $guard->isPublic($candidate, $candidate->getAttributes()) : null,
            'history' => $change->target_id ? ChangeRequest::query()->where('family_tree_id', $tree->id)->where('target_type', $change->target_type)->where('target_id', $change->target_id)
                ->whereKeyNot($change->id)->with(['submitter:id,name', 'reviewer:id,name'])->latest('id')->limit(10)->get() : collect(),
            'own' => $change->submitted_by === auth()->id(),
        ];

        return view('management.changes.show', compact('family', 'tree', 'change', 'referenceLabels', 'possibleDuplicates', 'claim', 'competing', 'sharedFamilies', 'context'));
    }

    public function decide(Request $request, string $family, int $change): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('review', $family);
        $change = ChangeRequest::query()->where('family_tree_id', $tree->id)->findOrFail($change);
        $data = $request->validate(['decision' => ['required', 'in:accepted,rejected'], 'decision_reason' => ['required', 'string', 'max:2000'], 'confirm_distinct' => ['nullable', 'boolean']]);
        $this->reviews->decide($tree, $change, $request->user(), $data['decision'], $data['decision_reason'], (bool) ($data['confirm_distinct'] ?? false));

        return back()->with('status', 'حُفظ قرار المراجعة وسجل التدقيق.');
    }
}
