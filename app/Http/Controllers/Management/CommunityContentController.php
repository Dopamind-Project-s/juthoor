<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CommunityEntity;
use App\Models\CommunityEvent;
use App\Models\ContentCitation;
use App\Models\Diwan;
use App\Models\EntityContentLink;
use App\Models\Family;
use App\Models\MediaItem;
use App\Models\NewsPost;
use App\Services\Community\CommunityContentModules;
use App\Services\Community\CommunityContentService;
use App\Services\Community\CommunityEntityAccess;
use App\Services\Community\CommunityHierarchy;
use App\Services\Community\CommunityPublishingService;
use App\Services\Community\MediaService;
use App\Services\Dashboards\AdministrationWorkspace;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(array_keys($model::PUBLICATION_STATUSES))]]);
        $column = match ($module) {
            'diwans', 'locations' => 'name', default => 'title'
        };
        $manageable = $this->access->manageableIds($request->user(), $familyModel);
        $publicIds = $this->hierarchy->publicIds($familyModel->id);

        return view('management.entities.content.index', [
            'family' => $familyModel, 'entity' => $entityModel, 'module' => $module, 'tree' => app(SelectedFamilyTree::class)->resolve($familyModel),
            'records' => $model::query()->where('owner_entity_id', $entityModel->id)->withCount('citations')
                ->when($filters['q'] ?? null, fn ($query, $term) => $query->where($column, 'like', '%'.$term.'%'))
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('publication_status', $status))->latest('id')->paginate(20)->withQueryString(),
            'linked' => $model::query()->where('owner_entity_id', '!=', $entityModel->id)
                ->whereIn('owner_entity_id', $familyModel->communityEntities()->select('id'))
                ->whereHas('entityLinks', fn ($links) => $links->where('community_entity_id', $entityModel->id))
                ->when($manageable !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereIn('owner_entity_id', $manageable)
                    ->orWhere(fn ($public) => $public->whereIn('owner_entity_id', $publicIds)->visibleTo($request->user()))))
                ->with('ownerEntity:id,name_ar')->latest('id')->limit(20)->get(),
            'canPublish' => $request->user()->can('publish', [$entityModel, $module]),
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
        $record = $module === 'media'
            ? app(MediaService::class)->upload($request->user(), $entityModel, $this->file($request, true), ...$this->input($request, $familyModel, $model))
            : $this->content->save($request->user(), $entityModel, new $model, ...$this->input($request, $familyModel, $model));

        return redirect()->route('management.entities.content.edit', [$familyModel->slug, $entityModel->id, $module, $record->id])->with('status', 'تم حفظ السجل.');
    }

    public function edit(Request $request, string $family, int $entity, string $module, int $record): View
    {
        [$familyModel, $entityModel] = $this->context($family, $entity, 'update');
        $recordModel = $this->record($entityModel, $module, $record);

        return view('management.entities.content.form', [
            ...$this->formData($request, $familyModel, $entityModel, $module, $recordModel),
            'citations' => $recordModel->citations()->with('source:id,title')->orderBy('id')->get(),
            'attachments' => method_exists($recordModel, 'mediaAttachments') ? $recordModel->mediaAttachments()->with('media')->get() : null,
            'library' => method_exists($recordModel, 'mediaAttachments')
                ? MediaItem::query()->where('family_id', $familyModel->id)->where('publication_status', '!=', MediaItem::STATUS_ARCHIVED)
                    ->when($recordModel instanceof Diwan, fn ($query) => $query->where('category', 'image'))->orderBy('title')->limit(300)->get(['id', 'title', 'category', 'publication_status', 'audience'])
                : collect(),
            'notices' => method_exists($recordModel, 'notices') ? $recordModel->notices()->with('author:id,name')->get() : collect(),
            'linkedPeople' => method_exists($recordModel, 'linkedPeople') ? $recordModel->linkedPeople()->get(['people.id', 'full_name_ar', 'title', 'honorific']) : collect(),
            'usage' => $recordModel instanceof MediaItem ? $recordModel->attachments()->with('attachable')->get() : collect(),
            'coverOf' => $recordModel instanceof MediaItem ? CommunityEntity::query()->where('cover_media_id', $recordModel->id)->get(['id', 'name_ar']) : collect(),
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
        $input = $this->input($request, $familyModel, $recordModel::class);
        if ($module === 'media' && $request->hasFile('file')) {
            app(MediaService::class)->replace($request->user(), $recordModel, $this->file($request, false));
        }
        $this->content->save($request->user(), $entityModel, $recordModel->refresh(), ...$input);

        return back()->with('status', 'تم تحديث السجل.');
    }

    /** Deletes an unused, unpublished media file; anything else is archived instead. */
    public function destroy(Request $request, string $family, int $entity, string $module, int $record): RedirectResponse
    {
        [$familyModel, $entityModel] = $this->context($family, $entity, 'update');
        abort_unless($module === 'media', 404);
        app(MediaService::class)->delete($request->user(), $this->record($entityModel, $module, $record));

        return redirect()->route('management.entities.content.index', [$familyModel->slug, $entityModel->id, $module])->with('status', 'حُذف الملف.');
    }

    public function attach(Request $request, string $family, int $entity, string $module, int $record): RedirectResponse
    {
        [$familyModel, $entityModel] = $this->context($family, $entity, 'update');
        $recordModel = $this->record($entityModel, $module, $record);
        abort_unless(method_exists($recordModel, 'mediaAttachments'), 404);
        $data = $request->validate(['media_item_id' => ['required', 'integer'], 'caption' => ['nullable', 'string', 'max:500']]);
        $media = MediaItem::query()->where('family_id', $familyModel->id)->whereKey($data['media_item_id'])->first()
            ?? throw ValidationException::withMessages(['media_item_id' => 'الملف ليس من مكتبة العائلة نفسها.']);
        app(MediaService::class)->attach($request->user(), $recordModel, $media, $data['caption'] ?? null);

        return back()->with('status', 'أُرفق الملف.');
    }

    public function detach(Request $request, string $family, int $entity, string $module, int $record, int $attachment): RedirectResponse
    {
        [, $entityModel] = $this->context($family, $entity, 'update');
        $recordModel = $this->record($entityModel, $module, $record);
        abort_unless(method_exists($recordModel, 'mediaAttachments'), 404);
        app(MediaService::class)->detach($request->user(), $recordModel, $recordModel->mediaAttachments()->findOrFail($attachment));

        return back()->with('status', 'أُزيل الملف من السجل دون حذفه من المكتبة.');
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
        $data = $request->validate(['decision' => ['required', Rule::in(['publish', 'decline', 'archive', 'restore'])], 'review_note' => ['nullable', 'string', 'max:2000'], 'sensitive_confirmation' => ['nullable', 'boolean']]);
        $this->content->review($request->user(), $this->record($entityModel, $module, $record), $data['decision'], $data['review_note'] ?? null, $request->boolean('sensitive_confirmation'));

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

    /** Public update, correction, cancellation or event schedule change; the replaced values stay visible. */
    public function notice(Request $request, string $family, int $entity, string $module, int $record): RedirectResponse
    {
        [, $entityModel] = $this->context($family, $entity, 'update');
        $recordModel = $this->record($entityModel, $module, $record);
        abort_unless($recordModel instanceof NewsPost || $recordModel instanceof CommunityEvent, 404);
        $kinds = $recordModel instanceof CommunityEvent ? ['update', 'correction', ...CommunityPublishingService::EVENT_CHANGES] : ['update', 'correction', 'cancellation'];
        $data = $request->validate([
            'kind' => ['required', Rule::in($kinds)], 'message' => ['required', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
            ...collect(CommunityEvent::contentRules($entityModel->family))->only(['location_name', 'public_address', 'city', 'governorate', 'latitude', 'longitude', 'maps_url'])->all(),
        ], ['message.required' => 'اكتب نص التحديث الذي سيظهر للقراء.']);
        $publishing = app(CommunityPublishingService::class);
        match (true) {
            in_array($data['kind'], ['update', 'correction'], true) => $publishing->addNotice($request->user(), $recordModel, $data['kind'], $data['message']),
            $recordModel instanceof NewsPost => $publishing->cancelNews($request->user(), $recordModel, $data['message']),
            default => $publishing->changeEvent($request->user(), $recordModel, $data['kind'], $data, $data['message']),
        };

        return back()->with('status', 'نُشر التحديث مع حفظ القيم السابقة.');
    }

    /** Draft announcement linked to the event (no copy of the event). */
    public function announce(Request $request, string $family, int $entity, string $module, int $record): RedirectResponse
    {
        [$familyModel, $entityModel] = $this->context($family, $entity, 'update');
        $event = $this->record($entityModel, $module, $record);
        abort_unless($event instanceof CommunityEvent, 404);
        $post = app(CommunityPublishingService::class)->announce($request->user(), $event);

        return redirect()->route('management.entities.content.edit', [$familyModel->slug, $entityModel->id, 'news', $post->id])->with('status', 'أُنشئ إعلان مسودة مرتبط بالمناسبة.');
    }

    /** Records waiting for a decision on entities the user may publish. */
    public function queue(Request $request, string $family): View
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        Gate::authorize('viewAny', [CommunityEntity::class, $familyModel]);
        $request->validate(['module' => ['nullable', Rule::in(array_keys(CommunityContentModules::MODULES))]]);
        $workspace = app(AdministrationWorkspace::class)->build($request->user(), $familyModel);
        $union = null;
        foreach (CommunityContentModules::MODULES as $key => $module) {
            if ($request->filled('module') && $request->query('module') !== $key) {
                continue;
            }
            $ids = $workspace['publicationScopes'][$key];
            if ($ids === []) {
                continue;
            }
            $query = $module['model']::query()->whereIn('owner_entity_id', $familyModel->communityEntities()->select('id'))
                ->when($ids !== null, fn ($query) => $query->whereIn('owner_entity_id', $ids))->where('publication_status', 'submitted')
                ->select('id as record_id', 'owner_entity_id', 'submitted_at')->selectRaw('? as module', [$key]);
            $union = $union ? $union->unionAll($query) : $query;
        }
        $pending = $union ? DB::query()->fromSub($union, 'content_queue')->orderBy('submitted_at')->orderBy('module')->orderBy('record_id')->paginate(20)->withQueryString()
            : new LengthAwarePaginator([], 0, 20);
        $records = [];
        foreach ($pending->getCollection()->groupBy('module') as $key => $rows) {
            $records[$key] = CommunityContentModules::model($key)::query()->whereKey($rows->pluck('record_id')->all())->with('ownerEntity:id,name_ar', 'creator:id,name')->get()->keyBy('id');
        }
        $pending->setCollection($pending->getCollection()->map(fn ($row) => ['module' => $row->module, 'record' => $records[$row->module][$row->record_id]]));

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
            'sensitive_confirmation' => ['nullable', 'boolean'],
            ...(method_exists($model, 'linkedPeople') ? ['people' => ['nullable', 'array', 'max:50'], 'people.*' => ['nullable', 'integer', Rule::exists('people', 'id')->whereNull('merged_into_id')->whereIn('family_tree_id', $family->familyTrees()->pluck('id')->all())]] : []),
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
        $governance['sensitive_confirmation'] = $request->boolean('sensitive_confirmation');
        if (method_exists($model, 'linkedPeople')) {
            // The empty "add a person" picker submits nothing useful; drop it.
            $governance['people'] = array_values(array_unique(array_map('intval', array_filter($governance['people'] ?? []))));
        }
        if (method_exists($model, 'prepareContentFields')) {
            $fields = $model::prepareContentFields($fields);
        }

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
            'canPublish' => $request->user()->can('publish', [$entity, $module]),
            'citations' => collect(), 'sources' => collect(), 'history' => collect(), 'attachments' => null, 'library' => collect(), 'usage' => collect(), 'coverOf' => collect(), 'notices' => collect(), 'linkedPeople' => collect(),
        ];
    }

    private function file(Request $request, bool $required): UploadedFile
    {
        // Type, signature, size and dimensions are checked by MediaStorage::inspect from the file's content.
        $request->validate(['file' => [$required ? 'required' : 'nullable', 'file']], ['file.required' => 'اختر ملفًا لرفعه.']);

        return $request->file('file');
    }

    /** @return array{Family, CommunityEntity} */
    private function context(string $family, int $entity, string $ability): array
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $entityModel = CommunityEntity::query()->where('family_id', $familyModel->id)->whereKey($entity)->firstOrFail();
        Gate::authorize($ability, [$entityModel, request()->route('module')]);

        return [$familyModel, $entityModel];
    }

    private function record(CommunityEntity $entity, string $module, int $id): Model
    {
        return CommunityContentModules::model($module)::query()->where('owner_entity_id', $entity->id)->whereKey($id)->firstOrFail();
    }
}
