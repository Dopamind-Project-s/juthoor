<?php

namespace App\Http\Controllers;

use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Services\AuditLogger;
use App\Services\Genealogy\CrossFamilyLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CrossFamilyLinkController extends Controller
{
    private function context(Request $request): ?Family
    {
        abort_unless($request->user()->is_active, 403);
        if ($slug = $request->query('family', $request->input('family'))) {
            $family = Family::query()->where('slug', $slug)->firstOrFail();
            Gate::authorize('manageMembers', $family);

            return $family;
        }
        abort_unless($request->user()->isSuperAdmin(), 403);

        return null;
    }

    public function index(Request $request, CrossFamilyLinkService $service)
    {
        $family = $this->context($request);
        $query = CrossFamilyLink::query()->when($family, fn ($q) => $q->where(fn ($q) => $q->where('left_family_id', $family->id)->orWhere('right_family_id', $family->id)));
        foreach ((clone $query)->where('status', 'approved')->get() as $link) {
            $service->current($link);
        }
        $request->validate(['status' => ['nullable', 'in:pending_review,approved,rejected,disputed,needs_review']]);
        $links = $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))->latest()->paginate(20)->withQueryString();

        return view('shared-ancestry.manage', compact('family', 'links'));
    }

    public function create(Request $request)
    {
        $family = $this->context($request);
        $request->validate(['q_left' => ['nullable', 'string', 'max:100'], 'q_right' => ['nullable', 'string', 'max:100']]);
        $query = Person::query()->with('familyTree.family')->whereHas('familyTree', fn ($q) => $q->active()->whereHas('family', fn ($q) => $q->active()));
        $leftPeople = (clone $query)->when($family, fn ($q) => $q->whereHas('familyTree', fn ($q) => $q->where('family_id', $family->id)), fn ($q) => $q->verifiedHistorical())
            ->when($request->filled('q_left'), fn ($q) => $q->where('full_name_ar', 'like', '%'.$request->query('q_left').'%'))->orderBy('full_name_ar')->limit(200)->get();
        $rightPeople = (clone $query)->verifiedHistorical()->when($family, fn ($q) => $q->whereHas('familyTree', fn ($q) => $q->where('family_id', '!=', $family->id)))
            ->when($request->filled('q_right'), fn ($q) => $q->where('full_name_ar', 'like', '%'.$request->query('q_right').'%'))->orderBy('full_name_ar')->limit(200)->get();

        return view('shared-ancestry.create', compact('family', 'leftPeople', 'rightPeople'));
    }

    public function store(Request $request, AuditLogger $audit, CrossFamilyLinkService $service)
    {
        $family = $this->context($request);
        $data = $request->validate([
            'left_person_id' => ['required', 'integer'], 'right_person_id' => ['required', 'integer', 'different:left_person_id'],
            'kind' => ['required', 'in:same_person,parent_child,child_parent'], 'claim' => ['required', 'string', 'max:5000'],
            'source_title' => ['required', 'string', 'max:255'], 'source_url' => ['required', 'url:http,https', 'max:2000'],
            'reference' => ['required', 'string', 'max:255'], 'locator' => ['required', 'string', 'max:255'], 'evidence' => ['required', 'string', 'max:5000'],
        ]);
        $link = DB::transaction(function () use ($request, $family, $data, $audit, $service) {
            $left = Person::query()->findOrFail($data['left_person_id']);
            $right = Person::query()->findOrFail($data['right_person_id']);
            if ($family && $left->familyTree->family_id !== $family->id) {
                abort(403);
            }
            if ($left->familyTree->family_id === $right->familyTree->family_id) {
                throw ValidationException::withMessages(['right_person_id' => 'اختر سجلًا من عائلة أخرى.']);
            }
            $sourcePerson = $left;
            // Family admins never acquire access to another family's private records.
            if (! $service->eligible($right) || (! $family && ! $service->eligible($left))) {
                throw ValidationException::withMessages(['right_person_id' => 'السجل الآخر غير متاح للمطابقة العامة.']);
            }
            if ($data['kind'] === 'child_parent') {
                [$left, $right] = [$right, $left];
                $data['kind'] = 'parent_child';
            }
            FamilyTree::query()->whereKey([$left->family_tree_id, $right->family_tree_id])->orderBy('id')->lockForUpdate()->get();
            $duplicate = CrossFamilyLink::query()->whereIn('status', ['pending_review', 'approved', 'needs_review', 'disputed'])
                ->where('kind', $data['kind'])->where(function ($q) use ($left, $right, $data) {
                    $q->where(fn ($q) => $q->where('left_person_id', $left->id)->where('right_person_id', $right->id));
                    if ($data['kind'] === 'same_person') {
                        $q->orWhere(fn ($q) => $q->where('left_person_id', $right->id)->where('right_person_id', $left->id));
                    }
                })->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['right_person_id' => 'يوجد اقتراح قائم لهذه المطابقة.']);
            }
            $link = CrossFamilyLink::query()->create([
                'left_person_id' => $left->id, 'right_person_id' => $right->id,
                'left_family_id' => $left->familyTree->family_id, 'right_family_id' => $right->familyTree->family_id,
                'kind' => $data['kind'], 'claim' => $data['claim'], 'submitted_by' => $request->user()->id,
            ]);
            $source = $sourcePerson->familyTree->family->sources()->create(['source_type' => 'website', 'title' => $data['source_title'], 'url' => $data['source_url'], 'reference' => 'CL-'.$link->id.': '.mb_substr($data['reference'], 0, 220), 'notes' => $data['evidence'], 'verification_status' => 'pending_review']);
            $link->citations()->create(['source_id' => $source->id, 'family_tree_id' => $sourcePerson->family_tree_id, 'field_key' => 'cross_family_match', 'locator' => $data['locator'], 'url' => $data['source_url'], 'notes' => $data['evidence'], 'verification_status' => 'pending_review']);
            $audit->record($request->user(), $sourcePerson->familyTree->family, $link, 'proposed', null, $audit->snapshot($link));

            return $link;
        });

        return redirect()->route('cross-links.show', $link)->with('status', 'تم تقديم الاقتراح للمراجعة؛ لم تتغير أي شجرة.');
    }

    public function show(Request $request, CrossFamilyLink $link, CrossFamilyLinkService $service)
    {
        abort_unless($link->canView($request->user()), 403);
        $service->current($link);
        $link->refresh()->load('citations.source', 'reviewer');
        $sides = collect([$link->leftPerson, $link->rightPerson])->map(function ($person) use ($request, $service) {
            if (! $person) {
                return collect();
            }
            $own = $request->user()->isSuperAdmin() || $request->user()->roleFor($person->familyTree->family_id) === 'admin';
            if (! $own && ! $service->eligible($person)) {
                return collect();
            }

            return $service->path($person, ! $own);
        });

        return view('shared-ancestry.review', compact('link', 'sides'));
    }

    public function evidence(Request $request, CrossFamilyLink $link, int $citation, AuditLogger $audit)
    {
        abort_unless($link->canView($request->user()), 403);
        $citation = $link->citations()->with('source.family')->findOrFail($citation);
        Gate::authorize('manageMembers', $citation->source->family);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'url' => ['required', 'url:http,https', 'max:2000'],
            'locator' => ['required', 'string', 'max:255'], 'notes' => ['required', 'string', 'max:5000'],
        ]);
        DB::transaction(function () use ($link, $citation, $request, $data, $audit) {
            $link = CrossFamilyLink::query()->lockForUpdate()->findOrFail($link->id);
            $before = $audit->snapshot($citation);
            $sourceBefore = $audit->snapshot($citation->source);
            $citation->source->update(['title' => $data['title'], 'url' => $data['url'], 'notes' => $data['notes'], 'verification_status' => 'pending_review']);
            $citation->update(['url' => $data['url'], 'locator' => $data['locator'], 'notes' => $data['notes'], 'verification_status' => 'pending_review']);
            $audit->record($request->user(), $citation->source->family, $citation->source, 'evidence_updated', $sourceBefore, $audit->snapshot($citation->source));
            $audit->record($request->user(), $citation->source->family, $citation, 'evidence_updated', $before, $audit->snapshot($citation));
            $before = $audit->snapshot($link->refresh());
            $link->update(['status' => 'pending_review', 'dependency_hash' => null, 'invalidation_reason' => 'قُدمت أدلة محدثة؛ يلزم قرار مراجعة جديد.']);
            $audit->record($request->user(), $citation->source->family, $link, 'resubmitted', $before, $audit->snapshot($link));
        });

        return back()->with('status', 'أُرسلت الأدلة المحدثة للمراجعة وسُحب أي اعتماد سابق.');
    }

    public function review(Request $request, CrossFamilyLink $link, CrossFamilyLinkService $service)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected,disputed'], 'reason' => ['required', 'string', 'min:10', 'max:5000'], 'evidence_confirmed' => ['nullable', 'boolean']]);
        $service->review($link, $request->user(), $data);

        return back()->with('status', 'تم تسجيل قرار المراجعة وسببه.');
    }

    public function publicIndex(CrossFamilyLinkService $service)
    {
        $links = CrossFamilyLink::query()->where('status', 'approved')->with('leftPerson.familyTree.family', 'rightPerson.familyTree.family', 'citations.source', 'reviewer')->get()
            ->filter(fn ($link) => $service->current($link));
        $families = Family::query()->active()->with(['familyTrees' => fn ($q) => $q->active()])->get();

        return view('shared-ancestry.public', compact('links', 'families', 'service'));
    }
}
