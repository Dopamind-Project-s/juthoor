<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use App\Models\CommunityLocation;
use App\Models\EntityNarrative;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Assembles an entity portal from the hierarchy, the content modules and the existing genealogy records, without
 * copying any of them. One scope («هذا المستوى فقط / مع الفروع التابعة») drives every section: child entities,
 * content modules (EntityContentScope), sub-entity founders and statistics.
 */
class CommunityEntityPage
{
    /**
     * Portal sections in display order; content modules come from CommunityContentModules.
     *
     * @var array<string, array{label: string, icon: string}>
     */
    public const SECTIONS = [
        'overview' => ['label' => 'نظرة عامة', 'icon' => 'info-circle'],
        'history' => ['label' => 'التاريخ والأصول', 'icon' => 'hourglass-split'],
        'archive' => ['label' => 'الأرشيف والوثائق', 'icon' => 'archive'],
        'locations' => ['label' => 'المناطق والانتشار', 'icon' => 'geo-alt'],
        'genealogy' => ['label' => 'المؤسس والنسب', 'icon' => 'diagram-3'],
        'statistics' => ['label' => 'الإحصاءات', 'icon' => 'bar-chart'],
        'diwans' => ['label' => 'الدواوين والمضافات', 'icon' => 'house-door'],
        'achievements' => ['label' => 'شخصيات وإنجازات', 'icon' => 'person-badge'],
        'media' => ['label' => 'الصور والوسائط', 'icon' => 'images'],
        'sources' => ['label' => 'المصادر', 'icon' => 'journal-text'],
        'children' => ['label' => 'الكيانات التابعة', 'icon' => 'diagram-2'],
        'review' => ['label' => 'المراجعة والتصحيح', 'icon' => 'patch-check'],
    ];

    public function __construct(private readonly CommunityHierarchy $hierarchy) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CommunityEntity $entity, string $scope, bool $public, ?User $viewer = null): array
    {
        $nodes = $this->hierarchy->nodes($entity->family_id);
        $visibleIds = $public ? $this->hierarchy->publicIds($entity->family_id, $nodes) : array_keys($nodes);
        $ancestorIds = $this->hierarchy->ancestorIds($entity, $nodes);
        $descendantIds = array_values(array_intersect($this->hierarchy->descendantIds($entity, false, $nodes), $visibleIds));
        $entities = CommunityEntity::query()->whereIn('id', [...$ancestorIds, ...$descendantIds])->get()->keyBy('id');
        $descendants = $entities->only($descendantIds)->values();
        $inScope = $scope === EntityContentScope::DIRECT ? collect([$entity]) : collect([$entity])->merge($descendants);
        $content = $this->content($entity, $scope, $public ? $visibleIds : null, $viewer);
        // One graph per page build, shared by the preview and the statistics; never kept across requests.
        $genealogy = app(CommunityGenealogy::class);

        return [
            'ancestors' => collect($ancestorIds)->map(fn (int $id) => $entities->get($id))->filter()->values(),
            'children' => $scope === EntityContentScope::DIRECT
                ? collect($this->hierarchy->ordered($descendants->where('parent_id', $entity->id)->values()))
                : collect($this->hierarchy->ordered($descendants)),
            'directChildCount' => $descendants->where('parent_id', $entity->id)->count(),
            'descendantCount' => $descendants->count(),
            'genealogy' => $genealogy->preview($entity, $public),
            'subFounders' => $scope === EntityContentScope::DIRECT ? collect() : $this->subFounders($genealogy, $descendants, $public),
            'statistics' => (new CommunityStatistics($genealogy))->compute($entity, $inScope, $public),
            'content' => $content,
            'publicPeople' => $this->publicPeople($content['achievements']->merge($content['archive'])),
            ...$this->media($entity, $content, $viewer),
            'sources' => $this->sources($content, $entity),
            'completeness' => $this->completeness($entity),
            'sections' => self::SECTIONS,
        ];
    }

    /**
     * How complete the introductory profile is; it says nothing about the accuracy of the information.
     *
     * @return array{done: int, total: int, missing: array<int, string>}
     */
    public function completeness(CommunityEntity $entity): array
    {
        $direct = fn (string $model) => $model::query()->onEntityPage($entity, EntityContentScope::DIRECT, null)->exists();
        $items = [
            'نبذة تعريفية' => filled($entity->summary),
            'تعريف مفصل' => filled($entity->description),
            'ربط بشجرة أو فرع نسبي' => $entity->branch_id !== null || $entity->family_tree_id !== null,
            'تاريخ مراجعة' => $entity->reviewed_at !== null,
            'سرد تاريخي منشور' => $direct(EntityNarrative::class),
            'منطقة جغرافية منشورة' => $direct(CommunityLocation::class),
        ];

        return ['done' => count(array_filter($items)), 'total' => count($items), 'missing' => array_keys(array_filter($items, fn (bool $done) => ! $done))];
    }

    /**
     * Every module through the shared scope, with approved evidence and linked entities eager-loaded.
     *
     * @param  array<int, int>|null  $eligibleIds
     * @return array<string, Collection<int, mixed>>
     */
    private function content(CommunityEntity $entity, string $scope, ?array $eligibleIds, ?User $viewer): array
    {
        $content = [];
        foreach (CommunityContentModules::MODULES as $key => $module) {
            $content[$key] = $module['model']::query()->onEntityPage($entity, $scope, $viewer, $eligibleIds)
                ->with(['citations' => fn ($citations) => $citations->approved()->with('source:id,title,url,source_type'), 'entityLinks.entity:id,name_ar,slug,family_id'])
                ->when(in_array($key, ['achievements', 'archive'], true), fn ($query) => $query->with('person:id,full_name_ar,family_tree_id', 'person.familyTree:id,slug'))
                ->when(method_exists($module['model'], 'mediaAttachments'), fn ($query) => $query->with('mediaAttachments.media.ownerEntity'))
                ->when($key === 'media', fn ($query) => $query->with('ownerEntity'))
                ->get();
        }
        $sections = array_flip(array_keys(EntityNarrative::SECTIONS));
        $content['history'] = $content['history']->sortBy([fn ($a, $b) => $sections[$a->section] <=> $sections[$b->section], fn ($a, $b) => ($a->account === 'primary' ? 0 : 1) <=> ($b->account === 'primary' ? 0 : 1), ['start_year', 'asc'], ['sort_order', 'asc'], ['id', 'asc']])->values();
        $content['locations'] = $content['locations']->sortBy([['kind', 'asc'], ['name', 'asc']])->values();
        $content['diwans'] = $content['diwans']->sortBy([['name', 'asc'], ['id', 'asc']])->values();
        $content['archive'] = $content['archive']->sortBy([['start_year', 'asc'], ['title', 'asc'], ['id', 'asc']])->values();
        $content['media'] = $content['media']->sortBy([['historical_year', 'asc'], ['title', 'asc'], ['id', 'asc']])->values();
        // Alphabetical by person, never by status or importance.
        $content['achievements'] = $content['achievements']->sortBy([fn ($a, $b) => strcmp((string) $a->person?->full_name_ar, (string) $b->person?->full_name_ar), ['title', 'asc']])->values();

        return $content;
    }

    /**
     * Founders of the descendant entities' branches, for the «with sub-branches» genealogy view.
     *
     * @param  Collection<int, CommunityEntity>  $descendants
     * @return Collection<int, array{entity: CommunityEntity, founder: Person}>
     */
    private function subFounders(CommunityGenealogy $genealogy, Collection $descendants, bool $public): Collection
    {
        $anchors = collect($genealogy->anchors($descendants, $public))->filter(fn (array $anchor) => $anchor['branch'] !== null);
        $people = Person::query()->whereIn('id', $anchors->pluck('branch.root_person_id'))->when($public, fn ($query) => $query->publiclyVisible())->get(['id', 'full_name_ar', 'family_tree_id'])->keyBy('id');

        return $anchors->map(fn (array $anchor) => ['entity' => $anchor['entity'], 'branch' => $anchor['branch'], 'founder' => $people->get($anchor['branch']->root_person_id)])
            ->filter(fn (array $row) => $row['founder'] !== null)->values();
    }

    /**
     * Files the viewer may read, by the same rule as the media route: attached files, the gallery and the cover.
     *
     * @param  array<string, Collection<int, mixed>>  $content
     * @return array{visibleMedia: array<int, true>, cover: ?MediaItem}
     */
    private function media(CommunityEntity $entity, array $content, ?User $viewer): array
    {
        $access = app(MediaAccess::class);
        $items = collect($content)->flatten(1)->flatMap(fn ($record) => $record->relationLoaded('mediaAttachments') ? $record->mediaAttachments->pluck('media') : [])
            ->merge($content['media'])->push($entity->coverMedia)->filter()->unique('id');
        $visible = $items->filter(fn (MediaItem $media) => $access->canView($viewer, $media))->mapWithKeys(fn (MediaItem $media) => [$media->id => true])->all();
        $cover = $entity->coverMedia && isset($visible[$entity->coverMedia->id]) && $entity->coverMedia->isImage() ? $entity->coverMedia : null;

        return ['visibleMedia' => $visible, 'cover' => $cover];
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return array<int, true>
     */
    private function publicPeople(Collection $records): array
    {
        $ids = $records->pluck('person_id')->filter()->unique()->all();

        return $ids === [] ? [] : array_fill_keys(Person::query()->whereIn('id', $ids)->publiclyVisible()->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /**
     * Distinct sources behind the approved evidence shown on the page, plus the population estimate's source.
     *
     * @param  array<string, Collection<int, mixed>>  $content
     * @return Collection<int, mixed>
     */
    private function sources(array $content, CommunityEntity $entity): Collection
    {
        $sources = collect($content)->flatten(1)->flatMap(fn ($record) => $record->citations->pluck('source'))->filter();
        if ($entity->population_estimate !== null && $entity->populationEstimateSource) {
            $sources->push($entity->populationEstimateSource);
        }

        return $sources->unique('id')->sortBy('title')->values();
    }
}
