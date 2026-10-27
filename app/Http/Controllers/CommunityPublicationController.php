<?php

namespace App\Http\Controllers;

use App\Models\CommunityEntity;
use App\Models\CommunityEvent;
use App\Models\Diwan;
use App\Models\Family;
use App\Models\MediaItem;
use App\Models\NewsPost;
use App\Models\Person;
use App\Services\Community\CommunityHierarchy;
use App\Services\Community\EntityContentScope;
use App\Services\Community\MediaAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Public news lists, calendar and detail pages. Every list goes through EntityContentScope (the portal's scope switch)
 * and the module's visibleTo (publication, audience and publication window); a detail page additionally needs the owner
 * entity's page to be public, or a manager previewing it. Lists are paginated in the database.
 */
class CommunityPublicationController extends Controller
{
    public function __construct(private readonly CommunityHierarchy $hierarchy) {}

    public function news(Request $request, string $family, string $entity): View
    {
        [$familyModel, $entityModel, $scope, $eligible] = $this->context($request, $family, $entity);
        $category = array_key_exists((string) $request->query('category'), NewsPost::CATEGORIES) ? (string) $request->query('category') : null;
        $search = trim((string) $request->query('q'));
        $posts = NewsPost::query()->onEntityPage($entityModel, $scope, $request->user(), $eligible)
            ->when($category, fn (Builder $query) => $query->where('category', $category))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $text) => $text->where('title', 'like', '%'.$search.'%')->orWhere('summary', 'like', '%'.$search.'%')->orWhere('body', 'like', '%'.$search.'%')))
            ->with('ownerEntity:id,name_ar,slug', 'notices')
            ->orderByRaw('coalesce(publish_at, reviewed_at, created_at) desc')->orderByDesc('id')
            ->paginate(config('community.per_page'))->withQueryString();

        return view('community.news.index', ['family' => $familyModel, 'entity' => $entityModel, 'scope' => $scope, 'category' => $category, 'search' => $search, 'posts' => $posts]);
    }

    public function calendar(Request $request, string $family, string $entity): View
    {
        [$familyModel, $entityModel, $scope, $eligible] = $this->context($request, $family, $entity);
        $zone = config('community.timezone');
        $category = array_key_exists((string) $request->query('category'), CommunityEvent::CATEGORIES) ? (string) $request->query('category') : null;
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? CarbonImmutable::createFromFormat('!Y-m', (string) $request->query('month'), $zone) : CarbonImmutable::now($zone)->startOfMonth();
        $events = fn () => CommunityEvent::query()->onEntityPage($entityModel, $scope, $request->user(), $eligible)
            ->when($category, fn (Builder $query) => $query->where('category', $category))->with('ownerEntity:id,name_ar,slug');
        $now = now();

        return view('community.events.calendar', [
            'family' => $familyModel, 'entity' => $entityModel, 'scope' => $scope, 'category' => $category, 'month' => $month, 'zone' => $zone,
            'monthEvents' => $events()->whereBetween('starts_at', [$month->utc(), $month->endOfMonth()->utc()])->orderBy('starts_at')->get()
                ->groupBy(fn (CommunityEvent $event) => $event->starts_at->setTimezone($zone)->format('Y-m-d')),
            'upcoming' => $events()->whereRaw('coalesce(ends_at, starts_at) >= ?', [$now])->orderBy('starts_at')->limit(20)->get(),
            'past' => $events()->whereRaw('coalesce(ends_at, starts_at) < ?', [$now])->orderByDesc('starts_at')->paginate(10, ['*'], 'past')->withQueryString(),
            'subEntities' => CommunityEntity::query()->where('parent_id', $entityModel->id)->whereIn('id', $eligible ?? CommunityEntity::query()->where('family_id', $familyModel->id)->pluck('id')->all())->orderBy('name_ar')->get(['id', 'name_ar', 'slug']),
        ]);
    }

    public function showNews(Request $request, string $family, int $post): View
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $record = $this->visibleRecord($request, $familyModel, NewsPost::class, $post, ['event', 'deceasedPerson']);

        return view('community.news.show', ['family' => $familyModel, 'post' => $record, ...$this->related($request, $familyModel, $record)]);
    }

    public function showEvent(Request $request, string $family, int $event): View
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $record = $this->visibleRecord($request, $familyModel, CommunityEvent::class, $event, []);
        // Only announcements the viewer may see are listed beside the event.
        $announcements = NewsPost::query()->where('event_id', $record->id)->visibleTo($request->user())->get(['id', 'title', 'category']);

        return view('community.events.show', ['family' => $familyModel, 'event' => $record, 'announcements' => $announcements, ...$this->related($request, $familyModel, $record)]);
    }

    /** One diwan or madafa, with its public images, places and the public entity pages it belongs to. */
    public function showDiwan(Request $request, string $family, int $diwan): View
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $record = Diwan::query()->whereKey($diwan)->whereHas('ownerEntity', fn (Builder $owner) => $owner->where('family_id', $familyModel->id))
            ->with(['ownerEntity', 'mediaAttachments.media.ownerEntity', 'citations' => fn ($citations) => $citations->approved()->with('source:id,title,url,source_type'), 'entityLinks.entity:id,name_ar,slug,family_id,entity_type'])->firstOrFail();
        $publicIds = $this->hierarchy->publicIds($familyModel->id);
        $manager = $request->boolean('preview') && $request->user()?->hasVerifiedEmail() && $request->user()->can('update', $record->ownerEntity);
        $public = in_array($record->owner_entity_id, $publicIds, true) && Diwan::query()->whereKey($diwan)->visibleTo($request->user())->exists();
        abort_unless($manager || $public, 404);
        $access = app(MediaAccess::class);
        $images = $record->mediaAttachments->pluck('media')->filter(fn (?MediaItem $media) => $media && $media->isImage() && $access->canView($request->user(), $media))->values();
        // Entity pages shown beside the diwan: public ones only, each once (a shared diwan is one record).
        $entities = $record->entityLinks->pluck('entity')->filter(fn ($entity) => $entity && in_array($entity->id, $publicIds, true))->unique('id')->values();
        $others = Diwan::query()->whereIn('owner_entity_id', $entities->pluck('id')->push($record->owner_entity_id)->unique())->whereKeyNot($record->id)
            ->visibleTo($request->user())->orderBy('name')->limit(6)->get(['id', 'name', 'kind', 'city', 'governorate', 'owner_entity_id']);

        return view('community.diwans.show', ['family' => $familyModel, 'diwan' => $record, 'images' => $images, 'entities' => $entities, 'others' => $others, 'preview' => $manager && ! $public]);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $with
     */
    private function visibleRecord(Request $request, Family $family, string $model, int $id, array $with): Model
    {
        $record = $model::query()->whereKey($id)->whereHas('ownerEntity', fn (Builder $owner) => $owner->where('family_id', $family->id))
            ->with(['ownerEntity', 'notices', 'mediaAttachments.media.ownerEntity', 'linkedPeople', 'citations' => fn ($citations) => $citations->approved()->with('source:id,title,url'), 'entityLinks.entity:id,name_ar,slug', ...$with])->firstOrFail();
        $manager = $request->boolean('preview') && $request->user()?->hasVerifiedEmail() && $request->user()->can('update', $record->ownerEntity);
        $public = in_array($record->owner_entity_id, $this->hierarchy->publicIds($family->id), true)
            && $model::query()->whereKey($id)->visibleTo($request->user())->exists();
        abort_unless($manager || $public, 404);

        return $record;
    }

    /**
     * People and files the viewer may see on a detail page.
     *
     * @return array{people: Collection<int, Person>, publicPeople: array<int, true>, visibleMedia: array<int, true>, preview: bool}
     */
    private function related(Request $request, Family $family, Model $record): array
    {
        $isMember = (bool) $request->user()?->is_active && ($request->user()->isSuperAdmin() || $request->user()->roleFor($family) !== null);
        $ids = $record->linkedPeople->pluck('id')->push($record->getAttribute('deceased_person_id'))->filter()->all();
        $public = $ids === [] ? [] : array_fill_keys(Person::query()->whereIn('id', $ids)->publiclyVisible()->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $access = app(MediaAccess::class);

        return [
            // Visitors see only publicly visible people; members also see the others' names, without links.
            'people' => $record->linkedPeople->filter(fn (Person $person) => $isMember || isset($public[$person->id]))->values(),
            'publicPeople' => $public,
            'visibleMedia' => $record->mediaAttachments->pluck('media')->filter()->filter(fn ($media) => $access->canView($request->user(), $media))->mapWithKeys(fn ($media) => [$media->id => true])->all(),
            'preview' => $request->boolean('preview'),
        ];
    }

    /** @return array{Family, CommunityEntity, string, array<int, int>|null} */
    private function context(Request $request, string $family, string $entity): array
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $entityModel = CommunityEntity::query()->where('family_id', $familyModel->id)->where('slug', $entity)->firstOrFail();
        $publicIds = $this->hierarchy->publicIds($familyModel->id);
        abort_unless(in_array($entityModel->id, $publicIds, true), 404);
        $scope = array_key_exists((string) $request->query('scope'), EntityContentScope::MODES) ? (string) $request->query('scope') : EntityContentScope::WITH_DESCENDANTS;

        return [$familyModel, $entityModel, $scope, $publicIds];
    }
}
