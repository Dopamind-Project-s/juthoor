<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\MediaItem;
use App\Models\Person;
use App\Services\ArabicNameNormalizer;
use Illuminate\Support\Collection;

/**
 * The public hierarchy directory (tribe → clan → fakhd → sub-fakhd → branch): public entities only, in hierarchy order,
 * with the founder of a published branch (never inferred from a name), published genealogy records counted once through
 * the public graph, sub-entity counts and a public cover image. Search covers entity names, recorded alternate names
 * and published founder names.
 */
class EntityDirectory
{
    public function __construct(
        private readonly CommunityHierarchy $hierarchy,
        private readonly ArabicNameNormalizer $normalizer,
        private readonly MediaAccess $media,
    ) {}

    /**
     * @param  array{q?: ?string, type?: ?string, within?: ?int}  $filters
     * @return array{items: array<int, array<string, mixed>>, types: array<string, string>, roots: Collection<int, CommunityEntity>, total: int, filtered: bool}
     */
    public function build(Family $family, array $filters = []): array
    {
        $nodes = $this->hierarchy->nodes($family->id);
        $publicIds = $this->hierarchy->publicIds($family->id, $nodes);
        $entities = CommunityEntity::query()->whereIn('id', $publicIds)->with(['coverMedia.ownerEntity'])->get()->keyBy('id');
        $genealogy = app(CommunityGenealogy::class);
        $anchors = collect($genealogy->anchors($entities->values(), true))->keyBy(fn (array $anchor) => $anchor['entity']->id);
        $founders = Person::query()->whereIn('id', $anchors->pluck('branch.root_person_id')->filter()->unique())->publiclyVisible()
            ->get(['id', 'full_name_ar', 'family_tree_id', 'verification_status'])->keyBy('id');
        $directChildren = $entities->countBy('parent_id');

        $items = [];
        foreach ($this->hierarchy->ordered($entities->values()) as ['entity' => $entity, 'depth' => $depth]) {
            $anchor = $anchors->get($entity->id);
            $branch = $anchor['branch'] ?? null;
            $founder = $branch ? $founders->get($branch->root_person_id) : null;
            $treeId = $anchor ? $anchor['tree']->id : null;
            $items[] = [
                'entity' => $entity,
                'depth' => $depth,
                'parent' => $entity->parent_id ? $entities->get($entity->parent_id) : null,
                'path' => collect($this->hierarchy->ancestorIds($entity, $nodes))->map(fn (int $id) => $entities->get($id)?->name_ar)->filter()->values()->all(),
                'founder' => $founder,
                'branch' => $branch,
                'people' => $founder ? count($genealogy->members($treeId, $founder->id, 'public')) : null,
                'children' => (int) ($directChildren[$entity->id] ?? 0),
                'descendants' => count(array_intersect($this->hierarchy->descendantIds($entity, false, $nodes), $publicIds)),
                'cover' => $this->cover($entity->coverMedia),
                'treeUrl' => $branch && $anchor ? route('genealogy.branch', [$family->slug, $branch->slug, 'tree' => $anchor['tree']->slug]) : null,
            ];
        }
        $total = count($items);
        $filtered = false;
        if (filled($filters['q'] ?? null)) {
            $needle = $this->normalizer->normalize((string) $filters['q']);
            $items = array_values(array_filter($items, fn (array $item) => collect([$item['entity']->name_ar, ...($item['entity']->alternate_names ?? []), $item['founder']?->full_name_ar])
                ->filter()->contains(fn (string $name) => str_contains($this->normalizer->normalize($name), $needle))));
            $filtered = true;
        }
        if (filled($filters['type'] ?? null)) {
            $items = array_values(array_filter($items, fn (array $item) => $item['entity']->entity_type === $filters['type']));
            $filtered = true;
        }
        if (filled($filters['within'] ?? null) && ($within = $entities->get((int) $filters['within']))) {
            $scope = $this->hierarchy->descendantIds($within, true, $nodes);
            $items = array_values(array_filter($items, fn (array $item) => in_array($item['entity']->id, $scope, true)));
            $filtered = true;
        }

        return [
            'items' => $items,
            'types' => $entities->pluck('type_label', 'entity_type')->all(),
            'roots' => $entities->filter(fn (CommunityEntity $entity) => $entity->parent_id === null || ! $entities->has($entity->parent_id) || ($directChildren[$entity->id] ?? 0) > 0)->sortBy('name_ar')->values(),
            'total' => $total,
            'filtered' => $filtered,
        ];
    }

    private function cover(?MediaItem $media): ?MediaItem
    {
        return $media && $media->isImage() && $this->media->canView(null, $media) ? $media : null;
    }
}
