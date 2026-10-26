<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CommunityEntity;
use App\Models\ContentCitation;
use App\Models\EntityContentLink;
use App\Models\Family;
use App\Services\Community\CommunityContentModules;
use App\Services\Community\CommunityContentService;
use App\Services\Community\CommunityEntityAccess;
use App\Services\Community\CommunityHierarchy;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * History, locations, diwans and achievements of one entity, managed from the entity's administration. Records belong
 * to the entity that owns them; every write goes through CommunityContentService.
 */
class CommunityContentController extends Controller
{
    public function __construct(
        private readonly CommunityContentService $content,
        private readonly CommunityHierarchy $hierarchy,
        private readonly CommunityEntityAccess $access,
    ) {}

    public function index(Request $request, string $family, int $entity, string $module): View
    {
        [$familyModel, $entityModel] = $this->context($family, $entity, 'update');
        $model = CommunityContentModules::model($module);

        return view('management.entities.content.index', [
            'family' => $familyModel, 'entity' => $entityModel, 'module' => $module, 'tree' => app(SelectedFamilyTree::class)->resolve($familyModel),
            'records' => $model::query()->where('owner_entity_id', $entityModel->id)->withCount('citations')->latest('id')->get(),
            // Records owned elsewhere that are about or addressed to this entity: shown, never editable from here.
            'linked' => $model::query()->where('owner_entity_id', '!=', $entityModel->id)
                ->whereHas('entityLinks', fn ($links) => $links->where('community_entity_id', $entityModel->id))->with('ownerEntity:id,name_ar')->get(),
            'canPublish' => $request->user()->can('publish', $entityModel),
        ]);
    }

    public function create(Request $request, string $family, int $entity, string $module): View
    {
        [$familyModel, $entityModel] = $this->context($family, $entity, 'update');
        $model = CommunityContentModules::model($module);

        return view('management.entities.content.form', $this->formData($request, $familyModel, $entityModel, $module, new $model(['audience' => 'public'])));
    }

    public function store(Request $request, string $family, int $entity, string $module): RedirectResponse
    {
        [$familyModel, $entityModel] = $this->context($family, $entity, 'update');
        $model = CommunityContentModules::model($module);
        $record = $this->content->save($request->user(), $entityModel, new $model, ...$this->input($request, $familyModel, $model));

        return redirect()->route('management.entities.content.edit', [$familyModel->slug, $entityModel->id, $module, $record->id])->with('status', 'تم حفظ السجل.');
    }

    public function edit(Request $request, string $family, int $entity, string $module, int $record): View
    {
        [$familyModel, $entityModel] = $this->context($family, $entity, 'update');
        $recordModel = $this->record($entityModel, $module, $record);

        return view('management.entities.content.form', [
            ...$this->formData($request, $familyModel, $entityModel, $module, $recordModel),
            'citations' => $recordModel->citations()->with('source:id,title')->orderBy('id')->get(),
            'sources' => $familyModel->sources()->whereDoesntHave('citations', fn ($citations) => $citations->whereNotNull('cross_family_link_id'))->orderBy('title')->limit(300)->get(['id', 'title']),
            'history' => AuditLog::query()->where('family_id', $familyModel->id)->visibleToMember($request->user(), $familyModel)
                ->where(fn ($logs) => $logs->where(fn ($own) => $own->where('auditable_type', $recordModel->getMorphClass())->where('auditable_id', $recordModel->id))
                    ->orWhere(fn ($evidence) => $evidence->where('auditable_type', (new ContentCitation)->getMorphClass())->whereIn('auditable_id', $recordModel->citations()->select('id'))))
                ->with('user:id,name')->latest('id')->limit(30)->get(),
        ]);
    }

    public function update(Request $request, string $family, int $entity, string $module, int $record): RedirectResponse
    {
        [$familyModel, $entityModel] = $this->context($family, $entity, 'update');
        $recordModel = $this->record($entityModel, $module, $record);
        $this->content->save($request->user(), $entityModel, $recordModel, ...$this->input($request, $familyModel, $recordModel::class));

        return back()->with('status', 'تم تحديث السجل.');
    }

    public function submit(Request $request, string $family, int $entity, string $module, int $record): RedirectResponse
    {
        [, $entityModel] = $this->context($family, $entity, 'update');
        $this->content->submit($request->user(), $this->record($entityModel, $module, $record));

        return back()->with('status', 'أُرسل السجل للمراجعة.');
    }

    public function review(Request $request, string $family, int $entity, string $module, int $record): RedirectResponse
    {
        [, $entityModel] = $this->context($family, $entity, 'publish');
        $data = $request->validate(['decision' => ['required', Rule::in(['publish', 'decline', 'archive', 'restore'])], 'review_note' => ['nullable', 'string', 'max:2000']]);
        $this->content->review($request->user(), $this->record($entityModel, $module, $record), $data['decision'], $data['review_note'] ?? null);

        return back()->with('status', 'تم تسجيل قرار المراجعة.');
    }

    public function cite(Request $request, string $family, int $entity, string $module, int $record): RedirectResponse
    {
        [, $entityModel] = $this->context($family, $entity, 'update');
        $data = $request->validate([
            'source_id' => ['required', 'integer'],
            'locator' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url:http,https', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'verification_status' => ['required', Rule::in(['pending_review', 'sourced', 'verified', 'unresolved'])],
        ]);
        $this->content->cite($request->user(), $this->record($entityModel, $module, $record), $data);

        return back()->with('status', 'أُضيف الاستشهاد.');
    }

    public function reviewCitation(Request $request, string $family, int $entity, string $module, int $record, int $citation): RedirectResponse
    {
        [, $entityModel] = $this->context($family, $entity, 'publish');
        $data = $request->validate(['verification_status' => ['required', Rule::in(['pending_review', 'sourced', 'verified', 'unresolved'])]]);
        $this->content->reviewCitation($request->user(), $this->record($entityModel, $module, $record)->citations()->findOrFail($citation), $data['verification_status']);

        return back()->with('status', 'تم تحديث حالة الاستشهاد.');
    }

    public function removeCitation(Request $request, string $family, int $entity, string $module, int $record, int $citation): RedirectResponse
    {
        [, $entityModel] = $this->context($family, $entity, 'update');
        $this->content->removeCitation($request->user(), $this->record($entityModel, $module, $record)->citations()->findOrFail($citation));

        return back()->with('status', 'أُزيل الاستشهاد.');
    }

    /** Records waiting for a decision on entities the user may publish. */
    public function queue(Request $request, string $family): View
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        Gate::authorize('viewAny', [CommunityEntity::class, $familyModel]);
        $entities = CommunityEntity::query()->where('family_id', $familyModel->id)->get()->filter(fn (CommunityEntity $entity) => $request->user()->can('publish', $entity));
        $pending = collect(CommunityContentModules::MODULES)->flatMap(fn (array $module, string $key) => $module['model']::query()
            ->whereIn('owner_entity_id', $entities->modelKeys())->where('publication_status', 'submitted')
            ->with('ownerEntity:id,name_ar', 'creator:id,name')->get()->map(fn (Model $record) => ['module' => $key, 'record' => $record]))
            ->sortBy(fn (array $row) => $row['record']->submitted_at)->values();

        return view('management.entities.content.queue', ['family' => $familyModel, 'tree' => app(SelectedFamilyTree::class)->resolve($familyModel), 'pending' => $pending]);
    }

    /**
     * @param  class-string<Model>  $model
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function input(Request $request, Family $family, string $model): array
    {
        $entityRule = Rule::exists('community_entities', 'id')->where('family_id', $family->id);
        $rules = $model::contentRules($family);
        $governanceRules = [
            'publication_status' => ['required', Rule::in(array_keys($model::PUBLICATION_STATUSES))],
            'verification_status' => ['required', Rule::in(array_keys($model::VERIFICATION_STATUSES))],
            'audience' => ['required', Rule::in(array_keys($model::AUDIENCES))],
            'subjects' => ['nullable', 'array'], 'subjects.*' => ['integer', $entityRule],
            'audiences' => ['nullable', 'array'], 'audiences.*' => ['integer', $entityRule],
        ];
        // One validation pass so every error is reported together.
        $data = $request->validate([...$governanceRules, ...$rules]);
        $governance = array_intersect_key($data, $governanceRules);
        $fields = array_intersect_key($data, $rules);
        foreach ($rules as $field => $fieldRules) {
            if (in_array('boolean', $fieldRules, true)) {
                $fields[$field] = $request->boolean($field);
            }
        }
        $governance['subjects'] = array_map('intval', $governance['subjects'] ?? []);
        $governance['audiences'] = array_map('intval', $governance['audiences'] ?? []);

        return [$fields, $governance];
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, Family $family, CommunityEntity $entity, string $module, Model $record): array
    {
        $manageable = $this->access->manageableIds($request->user(), $family);
        $links = $record->exists ? $record->entityLinks()->get(['community_entity_id', 'relation']) : collect();

        return [
            'family' => $family, 'entity' => $entity, 'module' => $module, 'record' => $record,
            'tree' => $entity->familyTree ?? app(SelectedFamilyTree::class)->resolve($family),
            'linkable' => $this->hierarchy->ordered(CommunityEntity::query()->where('family_id', $family->id)
                ->when($manageable !== null, fn ($query) => $query->whereIn('id', $manageable))->get()),
            'subjects' => $record->exists ? $links->where('relation', EntityContentLink::RELATION_SUBJECT)->pluck('community_entity_id')->map(fn ($id) => (int) $id)->all() : [$entity->id],
            'audiences' => $links->where('relation', EntityContentLink::RELATION_AUDIENCE)->pluck('community_entity_id')->map(fn ($id) => (int) $id)->all(),
            'canPublish' => $request->user()->can('publish', $entity),
            'citations' => collect(), 'sources' => collect(), 'history' => collect(),
        ];
    }

    /** @return array{Family, CommunityEntity} */
    private function context(string $family, int $entity, string $ability): array
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $entityModel = CommunityEntity::query()->where('family_id', $familyModel->id)->whereKey($entity)->firstOrFail();
        Gate::authorize($ability, $entityModel);

        return [$familyModel, $entityModel];
    }

    private function record(CommunityEntity $entity, string $module, int $id): Model
    {
        return CommunityContentModules::model($module)::query()->where('owner_entity_id', $entity->id)->whereKey($id)->firstOrFail();
    }
}
