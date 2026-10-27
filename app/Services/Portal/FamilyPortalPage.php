<?php

namespace App\Services\Portal;

use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\MemberProfile;
use App\Models\Person;
use App\Models\User;
use App\Services\Community\CommunityEntityPage;
use App\Services\Community\CommunityGenealogy;
use App\Services\Community\CommunityHierarchy;
use App\Services\Community\EntityContentScope;
use App\Services\Community\MemberDirectory;
use Illuminate\Support\Collection;

/**
 * View model of a family website. Everything comes from the existing records through the existing rules: the entity
 * hierarchy (public entities only), the entity portal assembly (content scope, audiences, publication windows, media
 * access), the genealogy graph (public people and edges only) and the opt-in directory (public fields only). Nothing is
 * copied, invented or taken from the legacy static arrays; an empty source yields an empty section.
 */
class FamilyPortalPage
{
    /** Directory fields a landing-page preview may show; contact and workplace details never appear here. */
    public const DIRECTORY_PREVIEW_FIELDS = ['photo', 'profession', 'sector', 'city'];

    public function __construct(
        private readonly CommunityHierarchy $hierarchy,
        private readonly CommunityEntityPage $entityPage,
        private readonly MemberDirectory $directory,
        private readonly PortalSettings $settings,
        private readonly FamilyHosts $hosts,
    ) {}

    /**
     * @param  array<string, mixed>  $branding  published (or, for a preview, draft) settings
     * @return array<string, mixed>
     */
    public function build(Family $family, array $branding, ?User $viewer = null): array
    {
        $genealogy = app(CommunityGenealogy::class);
        $tree = $family->familyTrees()->active()->where('is_primary', true)->first() ?? $family->familyTrees()->active()->orderBy('id')->first();
        $nodes = $this->hierarchy->nodes($family->id);
        $publicIds = $this->hierarchy->publicIds($family->id, $nodes);
        $entities = CommunityEntity::query()->whereIn('id', $publicIds)->with('branch')->get()->keyBy('id');
        $root = $this->rootEntity($entities, $branding['root_entity_id'] ?? null);
        // Every content section comes through the entity portal assembly of the root, with its sub-entities.
        $page = $root ? $this->entityPage->build($root, EntityContentScope::WITH_DESCENDANTS, true, $viewer) : null;

        return [
            'family' => $family,
            'branding' => $branding,
            'palette' => config('portals.accents')[$branding['accent']] ?? config('portals.accents')['heritage'],
            'cover' => $this->settings->publicImage($family, $branding['cover_media'] ?? null),
            'logo' => $this->settings->publicImage($family, $branding['logo_media'] ?? null),
            'sections' => collect($branding['sections'])->filter(fn (array $section) => $section['visible'])->pluck('key')->all(),
            'tree' => $tree,
            'rootEntity' => $root,
            'statistics' => $this->statistics($family, $tree, $genealogy, $entities, $page),
            'hierarchy' => $this->hierarchy($family, $entities, $tree, $genealogy),
            'genealogyPreview' => $page['genealogy'] ?? $this->treePreview($tree, $genealogy),
            'content' => $page['content'] ?? null,
            'visibleMedia' => $page['visibleMedia'] ?? [],
            'publicPeople' => $page['publicPeople'] ?? [],
            'directory' => $this->directoryPreview($family),
            'portalUrl' => $this->hosts->portalUrl($family),
        ];
    }

    /** @param Collection<int, CommunityEntity> $entities */
    private function rootEntity(Collection $entities, ?int $configured): ?CommunityEntity
    {
        if ($configured && $entities->has($configured)) {
            return $entities->get($configured);
        }

        // Top-level public entities, in their configured order; a family may have none (genealogy-only portal).
        return $entities->filter(fn (CommunityEntity $entity) => $entity->parent_id === null)->sortBy([['sort_order', 'asc'], ['id', 'asc']])->first();
    }

    /**
     * Counts from the database, each person counted once; registered records are not a population figure.
     *
     * @param  Collection<int, CommunityEntity>  $entities
     * @param  array<string, mixed>|null  $page
     * @return array<string, mixed>
     */
    private function statistics(Family $family, ?FamilyTree $tree, CommunityGenealogy $genealogy, Collection $entities, ?array $page): array
    {
        $graph = $tree ? $genealogy->graph($tree->id) : ['people' => [], 'public' => []];
        $content = $page['content'] ?? [];
        $count = fn (string $key) => isset($content[$key]) ? $content[$key]->count() : 0;

        return [
            'registered' => count($graph['people']),
            'published' => count($graph['public']),
            'verified' => $tree && $graph['public'] !== [] ? Person::query()->whereIn('id', array_keys($graph['public']))->where('verification_status', 'verified')->count() : 0,
            'branches' => $tree ? Branch::query()->where('family_tree_id', $tree->id)->published()->count() : 0,
            'fakhds' => $entities->whereIn('entity_type', ['fakhd', 'sub_fakhd'])->count(),
            'diwans' => $count('diwans'),
            'archive' => $count('archive'),
            'achievements' => $count('achievements'),
            'content' => collect($content)->except(['news', 'events'])->sum(fn ($records) => $records->count()),
            'computedAt' => now(),
        ];
    }

    /**
     * Public entities in hierarchy order with depth, founder (only when published) and published descendants; without
     * entities, the published genealogy branches. Each entity and person appears once.
     *
     * @param  Collection<int, CommunityEntity>  $entities
     * @return array{source: string, items: array<int, array<string, mixed>>}
     */
    private function hierarchy(Family $family, Collection $entities, ?FamilyTree $tree, CommunityGenealogy $genealogy): array
    {
        if ($entities->isNotEmpty()) {
            $anchors = collect($genealogy->anchors($entities->values(), true))->keyBy(fn (array $anchor) => $anchor['entity']->id);
            $founders = Person::query()->whereIn('id', $anchors->pluck('branch.root_person_id')->filter()->unique())->publiclyVisible()->get(['id', 'full_name_ar', 'family_tree_id'])->keyBy('id');
            $items = [];
            foreach ($this->hierarchy->ordered($entities->values()) as ['entity' => $entity, 'depth' => $depth]) {
                $anchor = $anchors->get($entity->id);
                $founder = $anchor && $anchor['branch'] ? $founders->get($anchor['branch']->root_person_id) : null;
                $items[] = [
                    'key' => 'entity-'.$entity->id, 'entity' => $entity, 'name' => $entity->name_ar, 'type' => $entity->type_label,
                    'depth' => $depth, 'parent' => $depth > 0 ? $entities->get($entity->parent_id)?->name_ar : null, 'summary' => $entity->summary, 'founder' => $founder,
                    'people' => $founder ? count($genealogy->members($anchor['tree']->id, $founder->id, 'public')) : null,
                    'url' => route('community.entities.show', [$family->slug, $entity->slug]),
                ];
            }

            return ['source' => 'entities', 'items' => $items];
        }
        if (! $tree) {
            return ['source' => 'none', 'items' => []];
        }
        $branches = Branch::query()->where('family_tree_id', $tree->id)->published()->orderBy('sort_order')->orderBy('id')->get();
        $founders = Person::query()->whereIn('id', $branches->pluck('root_person_id')->filter())->publiclyVisible()->get(['id', 'full_name_ar', 'family_tree_id'])->keyBy('id');
        $published = $branches->keyBy('id');
        $depth = [];
        $items = [];
        foreach ($this->orderBranches($branches) as $branch) {
            $depth[$branch->id] = $branch->parent_branch_id && isset($depth[$branch->parent_branch_id]) ? $depth[$branch->parent_branch_id] + 1 : 0;
            $founder = $founders->get($branch->root_person_id);
            $items[] = [
                'key' => 'branch-'.$branch->id, 'entity' => null, 'name' => $branch->name_ar, 'type' => $branch->type_label,
                'depth' => $depth[$branch->id], 'parent' => $depth[$branch->id] > 0 ? $published->get($branch->parent_branch_id)?->name_ar : null, 'summary' => $branch->description, 'founder' => $founder,
                'people' => $founder ? count($genealogy->members($tree->id, $founder->id, 'public')) : null,
                'url' => route('genealogy.branch', [$family->slug, $branch->slug, 'tree' => $tree->slug]),
            ];
        }

        return ['source' => 'branches', 'items' => $items];
    }

    /** Parents before children; a branch whose parent is hidden is shown at the top level. */
    private function orderBranches(Collection $branches): array
    {
        $ids = $branches->modelKeys();
        $children = $branches->groupBy(fn (Branch $branch) => in_array($branch->parent_branch_id, $ids, true) ? $branch->parent_branch_id : 0);
        $ordered = [];
        $walk = function (int $parent) use (&$walk, &$ordered, $children): void {
            foreach ($children->get($parent, collect()) as $branch) {
                $ordered[] = $branch;
                $walk($branch->id);
            }
        };
        $walk(0);

        return $ordered;
    }

    /** Founder and first generations of the whole tree when no entity anchors the portal. */
    private function treePreview(?FamilyTree $tree, CommunityGenealogy $genealogy): ?array
    {
        if (! $tree?->root_person_id) {
            return null;
        }
        $graph = $genealogy->graph($tree->id);
        if (! isset($graph['public'][$tree->root_person_id])) {
            return ['tree' => $tree, 'founder' => null, 'children' => collect()];
        }
        $childIds = $graph['children']['public'][$tree->root_person_id] ?? [];

        return [
            'tree' => $tree,
            'founder' => Person::query()->find($tree->root_person_id, ['id', 'full_name_ar', 'family_tree_id']),
            'children' => Person::query()->whereIn('id', $childIds)->orderBy('full_name_ar')->get(['id', 'full_name_ar', 'family_tree_id']),
        ];
    }

    /** @return Collection<int, array{profile: MemberProfile, fields: array<int, string>}> */
    private function directoryPreview(Family $family): Collection
    {
        // The landing page is a public page: public profiles and public-level fields only, whoever is viewing.
        return $this->directory->search(null, $family, [])->limit(6)->get()
            ->map(fn (MemberProfile $profile) => ['profile' => $profile, 'fields' => array_values(array_intersect($this->directory->visibleFields(null, $profile), self::DIRECTORY_PREVIEW_FIELDS))]);
    }
}
