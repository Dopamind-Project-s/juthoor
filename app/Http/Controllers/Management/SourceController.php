<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Assertion;
use App\Models\Citation;
use App\Models\Family;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Services\AuditLogger;
use App\Services\Genealogy\GenealogyAssertionService;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SourceController extends Controller
{
    public function __construct(private SelectedFamilyTree $selection, private AuditLogger $audit) {}

    private function context(string $slug): array
    {
        $family = Family::query()->active()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);

        return [$family, $this->selection->resolve($family)];
    }

    public function index(string $family): View
    {
        [$family,$tree] = $this->context($family);
        $sources = Source::query()->where('family_id', $family->id)
            ->when(! auth()->user()->isSuperAdmin() && auth()->user()->roleFor($family) !== 'admin', fn ($q) => $q->whereDoesntHave('citations', fn ($q) => $q->whereNotNull('cross_family_link_id')))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('management.sources.index', compact('family', 'tree', 'sources'));
    }

    public function show(string $family, int $source): View
    {
        [$family,$tree] = $this->context($family);
        $source = $family->sources()->findOrFail($source);
        if ($source->citations()->whereNotNull('cross_family_link_id')->exists()) {
            Gate::authorize('manageMembers', $family);
        }
        $citations = Citation::query()->where('family_tree_id', $tree->id)->where('source_id', $source->id)->with(['person', 'relationship.person', 'relationship.relatedPerson', 'assertion.person.familyTree.family', 'assertion.relatedPerson'])->orderByDesc('id')->paginate(20)->withQueryString();

        $canDownload = $source->file_path && Storage::disk('genealogy_private')->exists($source->file_path);

        return view('management.sources.show', compact('family', 'tree', 'source', 'citations', 'canDownload'));
    }

    private function data(Request $request, Family $family, ?Source $source = null): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:255'], 'source_type' => ['required', 'in:book,document,oral,website,archive,genealogy_dataset'], 'reference' => ['nullable', 'string', 'max:255', Rule::unique('sources')->where('family_id', $family->id)->ignore($source?->id)], 'url' => ['nullable', 'url:http,https', 'max:2000'], 'published_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000'], 'verification_status' => ['required', 'in:verified,sourced,pending_review,unresolved'], 'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,txt', 'max:10240']]);
    }

    public function store(Request $request, string $family): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('manageSources', $family);
        $data = $this->data($request, $family);
        $source = $this->save($request, $family, $data);

        return redirect()->route('management.sources.show', [$family->slug, $source->id, 'tree' => $tree->slug])->with('status', 'حُفظ المصدر في مكتبة العائلة الخاصة.');
    }

    public function update(Request $request, string $family, int $source): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('manageSources', $family);
        $source = $family->sources()->findOrFail($source);
        if ($source->citations()->whereNotNull('cross_family_link_id')->exists()) {
            Gate::authorize('manageMembers', $family);
        }
        $this->save($request, $family, $this->data($request, $family, $source), $source);

        return back()->with('status', 'تم تحديث المصدر.');
    }

    private function save(Request $request, Family $family, array $data, ?Source $source = null): Source
    {
        unset($data['file']);
        $path = null;
        $old = $source?->file_path;
        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('sources/'.$family->id, 'genealogy_private');
            abort_unless($path, 500);
            $data['file_path'] = $path;
        }
        try {
            $source = DB::transaction(function () use ($request, $family, $data, $source) {
                Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
                if ($source) {
                    $source = Source::query()->lockForUpdate()->findOrFail($source->id);
                }
                $before = $source ? $this->audit->snapshot($source) : null;
                $source ??= new Source;
                $source->family_id = $family->id;
                $source->fill($data);
                $source->save();
                $this->audit->record($request->user(), $family, $source, $before ? 'updated' : 'created', $before, $this->audit->snapshot($source));

                return $source;
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('genealogy_private')->delete($path);
            }throw $e;
        }
        if ($path && $old) {
            Storage::disk('genealogy_private')->delete($old);
        }

        return $source;
    }

    public function download(string $family, int $source): StreamedResponse
    {
        [$family,$tree] = $this->context($family);
        $source = $family->sources()->findOrFail($source);
        if ($source->citations()->whereNotNull('cross_family_link_id')->exists()) {
            Gate::authorize('manageMembers', $family);
        }
        abort_unless($source->file_path && Storage::disk('genealogy_private')->exists($source->file_path), 404);

        return Storage::disk('genealogy_private')->download($source->file_path, 'source-'.$source->id.'.'.pathinfo($source->file_path, PATHINFO_EXTENSION), ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function destroy(string $family, int $source): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('manageSources', $family);
        $source = $family->sources()->findOrFail($source);
        if ($source->citations()->whereNotNull('cross_family_link_id')->exists()) {
            Gate::authorize('manageMembers', $family);
        }
        DB::transaction(function () use ($family, $source): void {
            Source::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($source->people()->exists() || Citation::query()->where('source_id', $source->id)->exists() || PersonRelationship::query()->where('source_id', $source->id)->exists()) {
                throw ValidationException::withMessages(['source' => 'المصدر مرتبط باستشهادات أو سجلات. أزل الربط قبل حذفه.']);
            }
            $before = $this->audit->snapshot($source);
            $source->delete();
            $this->audit->record(auth()->user(), $family, $source, 'deleted', $before, null);
        });
        if ($source->file_path) {
            Storage::disk('genealogy_private')->delete($source->file_path);
        }

        return redirect()->route('management.sources.index', [$family->slug, 'tree' => $tree->slug])->with('status', 'حُذف المصدر غير المرتبط.');
    }

    public function cite(Request $request, string $family, int $source): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('manageSources', $family);
        $source = $family->sources()->findOrFail($source);
        if ($source->citations()->whereNotNull('cross_family_link_id')->exists()) {
            Gate::authorize('manageMembers', $family);
        }
        $data = $request->validate(['target_type' => ['required', 'in:person,relationship,assertion'], 'target_id' => ['required', 'integer'], 'field_key' => ['required', 'in:person,full_name_ar,birth_year,death_year,notes,father,assertion'], 'locator' => ['nullable', 'string', 'max:255'], 'url' => ['nullable', 'url:http,https', 'max:2000'], 'notes' => ['nullable', 'string', 'max:5000'], 'verification_status' => ['required', 'in:verified,sourced,pending_review,unresolved']]);
        DB::transaction(function () use ($request, $family, $tree, $source, $data): void {
            Source::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($data['target_type'] === 'assertion') {
                abort_unless($data['field_key'] === 'assertion', 422);
                $assertion = Assertion::query()->where('family_tree_id', $tree->id)->findOrFail($data['target_id']);
                app(GenealogyAssertionService::class)->cite($assertion, $request->user(), $source, Arr::only($data, ['locator', 'url', 'notes', 'verification_status']));

                return;
            } elseif ($data['target_type'] === 'person') {
                $tree->people()->lockForUpdate()->findOrFail($data['target_id']);
                abort_if(in_array($data['field_key'], ['father', 'assertion'], true), 422);
                $data['person_id'] = $data['target_id'];
            } else {
                $tree->personRelationships()->lockForUpdate()->findOrFail($data['target_id']);
                abort_unless($data['field_key'] === 'father', 422);
                $data['person_relationship_id'] = $data['target_id'];
            }
            unset($data['target_type'],$data['target_id']);
            $data += ['source_id' => $source->id, 'family_tree_id' => $tree->id];
            $citation = Citation::query()->create($data);
            $this->audit->record($request->user(), $family, $citation, 'created', null, $this->audit->snapshot($citation));
        });

        return back()->with('status', 'حُفظ الاستشهاد بالمعلومة المحددة.');
    }

    public function updateCitation(Request $request, string $family, int $source, int $citation): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('manageSources', $family);
        $source = $family->sources()->findOrFail($source);
        if ($source->citations()->whereNotNull('cross_family_link_id')->exists()) {
            Gate::authorize('manageMembers', $family);
        }
        $data = $request->validate(['locator' => ['nullable', 'string', 'max:255'], 'url' => ['nullable', 'url:http,https', 'max:2000'], 'notes' => ['nullable', 'string', 'max:5000'], 'verification_status' => ['required', 'in:verified,sourced,pending_review,unresolved']]);
        DB::transaction(function () use ($request, $family, $tree, $source, $citation, $data): void {
            $citation = Citation::query()->where('family_tree_id', $tree->id)->where('source_id', $source->id)->lockForUpdate()->findOrFail($citation);
            $before = $this->audit->snapshot($citation);
            $citation->update($data);
            $this->audit->record($request->user(), $family, $citation, 'updated', $before, $this->audit->snapshot($citation));
        });

        return back()->with('status', 'تم تحديث الاستشهاد.');
    }

    public function removeCitation(string $family, int $source, int $citation): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('manageSources', $family);
        $source = $family->sources()->findOrFail($source);
        if ($source->citations()->whereNotNull('cross_family_link_id')->exists()) {
            Gate::authorize('manageMembers', $family);
        }
        DB::transaction(function () use ($family, $tree, $source, $citation): void {
            $citation = Citation::query()->where('family_tree_id', $tree->id)->where('source_id', $source->id)->lockForUpdate()->findOrFail($citation);
            $before = $this->audit->snapshot($citation);
            $citation->delete();
            $this->audit->record(auth()->user(), $family, $citation, 'deleted', $before, null);
        });

        return back()->with('status', 'أُزيل ربط الاستشهاد دون حذف المصدر.');
    }
}
