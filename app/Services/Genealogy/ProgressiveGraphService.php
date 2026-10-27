<?php

namespace App\Services\Genealogy;

use App\Models\Branch;
use App\Models\CommunityEntity;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Services\Access\FamilyPermissions;
use App\Services\Community\CommunityHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProgressiveGraphService
{
    /** Sentinel id in URL templates; the tree replaces it with a node id client-side. */
    public const ID_PLACEHOLDER = 987654321987;

    /**
     * The full tree in id-ordered batches for the unified tree renderer. Only people and father edges the viewer may
     * see are included (public: Person/PersonRelationship::publiclyVisible, both ends visible). Attributes are sent once
     * as a dictionary; founders only from approved associations (published branches for visitors, the tree root);
     * action URLs as templates and only for permitted actions.
     *
     * @return array<string, mixed>
     */
    public function batch(FamilyTree $tree, int $after, int $limit, bool $publicOnly): array
    {
        $query = $tree->people()->when($publicOnly, fn ($query) => $query->publiclyVisible());
        $total = (clone $query)->count();
        $people = $query->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->with('personAttributes')->get(['id', 'family_tree_id', 'full_name_ar', 'title', 'honorific', 'verification_status']);
        $complete = $people->count() <= $limit;
        $people = $people->take($limit);
        $edges = $tree->personRelationships()->where('relationship_type', 'father')->where('is_primary', true)
            ->whereIn('person_id', $people->modelKeys())
            ->when($publicOnly, fn ($query) => $query->publiclyVisible()->whereHas('relatedPerson', fn ($query) => $query->publiclyVisible()))
            ->orderBy('id')->get(['id', 'person_id', 'related_person_id', 'verification_status']);
        $rootId = $tree->people()->when($publicOnly, fn ($q) => $q->publiclyVisible())->whereKey($tree->root_person_id)->value('id');
        $founders = $this->founders($tree, $people->modelKeys(), $publicOnly, $rootId);
        $attributes = [];

        return [
            'tree' => ['id' => $tree->id, 'name' => $tree->name_ar], 'root_id' => $rootId,
            'nodes' => $people->values()->map(function (Person $person) use ($tree, $founders, &$attributes): array {
                $ids = [];
                foreach ($person->setRelation('familyTree', $tree)->displayAttributes() as $attribute) {
                    $attributes[$attribute->id] ??= ['id' => $attribute->id, 'key' => $attribute->key, 'label' => $attribute->label, 'color' => $attribute->color, 'text_color' => $attribute->text_color];
                    $ids[] = $attribute->id;
                }

                return array_filter([
                    'id' => $person->id, 'name' => $person->display_name, 'verification' => $person->verification_status,
                    'attributes' => $ids, 'founder' => $founders[$person->id] ?? null,
                ], fn ($value) => $value !== null && $value !== []) + ['id' => $person->id];
            })->all(),
            'edges' => $edges->map(fn ($edge) => ['id' => $edge->id, 'child_id' => $edge->person_id, 'parent_id' => $edge->related_person_id, 'verification' => $edge->verification_status])->all(),
            'meta' => [
                'complete' => $complete, 'total' => $total, 'next_cursor' => $complete ? null : $people->last()->id,
                'attributes' => array_values($attributes),
                'templates' => $this->templates($tree, $publicOnly),
                'id_placeholder' => self::ID_PLACEHOLDER,
            ],
        ];
    }

    /**
     * Founder classification of the batch's people from approved associations only: a branch whose founder they are
     * (published branches for visitors) and, for its root, the tree itself. Entity links only to public entities.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function founders(FamilyTree $tree, array $ids, bool $publicOnly, ?int $rootId): array
    {
        $branches = Branch::query()->where('family_tree_id', $tree->id)->whereIn('root_person_id', $ids)
            ->when($publicOnly, fn ($query) => $query->published())->orderBy('sort_order')->orderBy('id')->get();
        $entityIds = $publicOnly ? app(CommunityHierarchy::class)->publicIds($tree->family_id) : null;
        $entities = CommunityEntity::query()->where('family_id', $tree->family_id)->whereIn('branch_id', $branches->modelKeys())
            ->when($entityIds !== null, fn ($query) => $query->whereIn('id', $entityIds))->get(['id', 'slug', 'name_ar', 'branch_id'])->keyBy('branch_id');
        $slug = $tree->family->slug;
        $founders = [];
        foreach ($branches as $branch) {
            $boundary = $branch->parent_branch_id !== null || $branch->branch_type === 'sub_fakhd' ? 'sub' : (in_array($branch->branch_type, ['fakhd', Branch::TYPE_FAMILY_GROUP], true) ? 'main' : null);
            if (isset($founders[$branch->root_person_id])) {
                $founders[$branch->root_person_id]['also'][] = $branch->name_ar;
                if ($boundary) {
                    $founders[$branch->root_person_id]['boundaries'] = array_values(array_unique([...($founders[$branch->root_person_id]['boundaries'] ?? []), $boundary]));
                }

                continue;
            }
            $entity = $entities->get($branch->id);
            $founders[$branch->root_person_id] = array_filter([
                'boundaries' => $boundary ? [$boundary] : [],
                'kind' => $branch->branch_type,
                'classification' => 'مؤسس '.($branch->type_label),
                'branch' => $branch->name_ar,
                'description' => $branch->description ? Str::limit($branch->description, 280) : null,
                'verification' => $branch->verification_status,
                'branch_url' => route('genealogy.branch', [$slug, $branch->slug, 'tree' => $tree->slug, 'private' => $publicOnly ? null : 1]),
                'entity_url' => $entity ? route('community.entities.show', [$slug, $entity->slug]) : null,
                'entity' => $entity?->name_ar,
            ], fn ($value) => $value !== null);
        }
        if ($rootId && in_array($rootId, $ids, true) && ! isset($founders[$rootId])) {
            $founders[$rootId] = ['kind' => 'root', 'classification' => 'الجد الجامع للشجرة', 'branch' => $tree->name_ar];
        }

        return $founders;
    }

    /**
     * URL templates for the viewer: the person page always; management actions only when permitted (each route checks
     * again). The id placeholder is replaced client-side.
     *
     * @return array<string, string>
     */
    private function templates(FamilyTree $tree, bool $publicOnly): array
    {
        $id = self::ID_PLACEHOLDER;
        $slug = $tree->family->slug;
        $user = auth()->user();
        $permissions = $user?->hasVerifiedEmail() ? app(FamilyPermissions::class)->for($user, $tree->family) : [];

        return array_filter([
            'person' => route('genealogy.person', [$slug, $id, 'tree' => $tree->slug, 'private' => $publicOnly ? null : 1]),
            'add_child' => in_array('person.create', $permissions, true) ? route('management.records.create', [$slug, 'people', 'tree' => $tree->slug, 'father_id' => $id]) : null,
            'edit' => in_array('person.edit-unpublished', $permissions, true) ? route('management.records.edit', [$slug, 'people', $id, 'tree' => $tree->slug]) : null,
            'propose' => in_array('person.propose', $permissions, true) ? route('management.changes.create', [$slug, 'tree' => $tree->slug, 'kind' => 'person', 'target' => $id]) : null,
            'manage' => in_array('person.propose', $permissions, true) ? route('management.people.show', [$slug, $id, 'tree' => $tree->slug]) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function build(FamilyTree $tree, int $rootId, int $depth, int $limit, bool $publicOnly, ?Branch $branch = null, string $mode = 'focus', int $after = 0): array
    {
        $allowed = fn (Builder $query): Builder => $publicOnly ? $query->publiclyVisible() : $query;
        $root = $allowed($tree->people()->getQuery())->whereKey($rootId)->firstOrFail();
        $nodes = collect([$root->id => $root]);
        $edges = collect();
        $ancestorNodes = collect();
        $ancestorEdges = collect();
        if ($branch) {
            [$ancestorNodes, $ancestorEdges] = $this->ancestors($tree, $branch->root_person_id, $publicOnly, min(GenealogyTraversalService::DEFAULT_MAX_DEPTH, $limit));
            foreach ($ancestorNodes as $person) {
                $nodes->put($person->id, $person);
            }
            $edges = $ancestorEdges;
        }
        $frontier = [$root->id];
        $truncated = false;

        for ($level = 0; $level < $depth && $frontier !== []; $level++) {
            $relationships = $this->relationships($tree, $publicOnly)->whereIn('related_person_id', $frontier)
                ->when($level === 0 && $after > 0, fn ($query) => $query->where('person_id', '>', $after))
                ->when($publicOnly, fn ($query) => $query->whereHas('person', fn ($query) => $query->publiclyVisible()))
                ->orderBy('person_id')->limit($limit + 1)->get();
            $candidateIds = $relationships->pluck('person_id')->unique()->values();
            $people = $allowed($tree->people()->getQuery())->whereKey($candidateIds)->get()->keyBy('id');
            $next = [];
            foreach ($relationships as $relationship) {
                if (! $people->has($relationship->person_id) || $nodes->has($relationship->person_id)) {
                    continue;
                }
                if ($nodes->count() >= $limit) {
                    $truncated = true;
                    break 2;
                }
                $nodes->put($relationship->person_id, $people[$relationship->person_id]);
                $edges->push($relationship);
                $next[] = $relationship->person_id;
            }
            $frontier = array_values(array_unique($next));
        }

        $states = [];
        if ($branch) {
            foreach ($ancestorNodes as $person) {
                $nodes->put($person->id, $person);
                $states[$person->id] = 'ancestor_path';
            }
            $edges = $edges->merge($ancestorEdges)->unique('id')->values();
            $states[$branch->root_person_id] = 'branch_root';
            foreach ($nodes->keys() as $id) {
                $states[$id] ??= $mode === 'isolated' ? 'branch_descendant' : 'branch_descendant';
            }
            if ($mode === 'focus' && $nodes->count() < $limit) {
                $pathIds = $ancestorNodes->pluck('id');
                $sideEdges = $this->relationships($tree, $publicOnly)
                    ->whereIn('related_person_id', $pathIds)->whereNotIn('person_id', $nodes->keys())
                    ->limit($limit - $nodes->count())->get();
                $sidePeople = $allowed($tree->people()->getQuery())->whereKey($sideEdges->pluck('person_id'))->get();
                foreach ($sidePeople as $person) {
                    $nodes->put($person->id, $person);
                    $states[$person->id] = 'dimmed';
                }
                $edges = $edges->merge($sideEdges->whereIn('person_id', $sidePeople->modelKeys()))->unique('id')->values();
            }
        }

        (new \Illuminate\Database\Eloquent\Collection($nodes->values()->all()))->loadMissing('personAttributes');
        $childCounts = $this->relationships($tree, $publicOnly)
            ->whereIn('related_person_id', $nodes->keys())
            ->when($after > 0, fn ($query) => $query->where(fn ($query) => $query->where('related_person_id', '!=', $rootId)->orWhere('person_id', '>', $after)))
            ->when($publicOnly, fn ($query) => $query->whereHas('person', fn ($query) => $query->publiclyVisible()))
            ->selectRaw('related_person_id, count(*) as aggregate')
            ->groupBy('related_person_id')->pluck('aggregate', 'related_person_id');
        $loadedCounts = $edges->countBy('related_person_id');

        $actions = $this->actions($tree);

        return [
            'tree' => ['id' => $tree->id, 'name' => $tree->name_ar],
            'root_id' => $rootId,
            'nodes' => $nodes->values()->map(fn (Person $person): array => [
                'id' => $person->id,
                'name' => $person->display_name,
                'title' => $person->title, 'honorific' => $person->honorific,
                'attributes' => $person->setRelation('familyTree', $tree)->displayAttributes()->map(fn ($attribute) => [
                    'id' => $attribute->id, 'label' => $attribute->label,
                    'color' => $attribute->color, 'text_color' => $attribute->text_color,
                ])->all(),
                'status' => $states[$person->id] ?? 'visible',
                'verification' => $person->verification_status,
                'has_more_children' => ! ($mode === 'isolated' && ($states[$person->id] ?? '') === 'ancestor_path') && (int) ($childCounts[$person->id] ?? 0) > (int) ($loadedCounts[$person->id] ?? 0),
                ...$actions($person),
                'url' => route('genealogy.person', [$tree->family->slug, $person->id, 'tree' => $tree->slug, 'private' => $publicOnly ? null : 1]),
            ])->all(),
            'edges' => $edges->map(fn (PersonRelationship $edge): array => [
                'id' => $edge->id, 'child_id' => $edge->person_id, 'parent_id' => $edge->related_person_id,
            ])->all(),
            'meta' => [
                'depth' => $depth, 'limit' => $limit, 'returned' => $nodes->count(),
                'truncated' => $truncated, 'complete' => ! $truncated && $childCounts->every(fn ($count, $id) => ($mode === 'isolated' && ($states[$id] ?? '') === 'ancestor_path') || (int) $count <= (int) ($loadedCounts[$id] ?? 0)),
                'next_frontier' => ($truncated || $frontier !== []) ? $frontier : [],
            ],
        ];
    }

    private function relationships(FamilyTree $tree, bool $publicOnly): Builder|HasMany
    {
        $query = $tree->personRelationships()->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true);

        return $publicOnly ? $query->publiclyVisible() : $query;
    }

    /** @return array{Collection<int, Person>, Collection<int, PersonRelationship>} */
    private function ancestors(FamilyTree $tree, int $personId, bool $publicOnly, int $remaining): array
    {
        $availableEdges = $this->relationships($tree, $publicOnly)->get()->keyBy('person_id');
        $orderedIds = collect();
        $orderedEdges = collect();
        $current = $personId;
        while ($remaining-- > 0) {
            $orderedIds->prepend($current);
            $edge = $availableEdges->get($current);
            if (! $edge) {
                break;
            }
            $orderedEdges->prepend($edge);
            $current = $edge->related_person_id;
        }

        $people = $tree->people()->when($publicOnly, fn ($query) => $query->publiclyVisible())
            ->whereKey($orderedIds)->get()->keyBy('id');
        $nodes = $orderedIds->map(fn (int $id) => $people->get($id))->filter()->values();
        $allowedIds = $nodes->pluck('id');
        $edges = $orderedEdges->filter(fn (PersonRelationship $edge) => $allowedIds->contains($edge->person_id)
            && $allowedIds->contains($edge->related_person_id))->values();

        return [$nodes, $edges];
    }

    /**
     * Node actions for the signed-in user; each one is authorized again by its own route.
     *
     * @return callable(Person): array{add_child_url: ?string, edit_url: ?string, propose_url: ?string, manage_url: ?string}
     */
    private function actions(FamilyTree $tree): callable
    {
        $user = auth()->user();
        $verified = (bool) $user?->hasVerifiedEmail();
        $permissions = $verified ? app(FamilyPermissions::class)->for($user, $tree->family) : [];
        [$canCreate, $canEdit, $canPropose] = [in_array('person.create', $permissions, true), in_array('person.edit-unpublished', $permissions, true), in_array('person.propose', $permissions, true)];
        $slug = $tree->family->slug;

        return fn (Person $person): array => [
            'add_child_url' => $canCreate ? route('management.records.create', [$slug, 'people', 'tree' => $tree->slug, 'father_id' => $person->id]) : null,
            'edit_url' => $canEdit ? route('management.records.edit', [$slug, 'people', $person->id, 'tree' => $tree->slug]) : null,
            'propose_url' => $canPropose ? route('management.changes.create', [$slug, 'tree' => $tree->slug, 'kind' => 'person', 'target' => $person->id]) : null,
            'manage_url' => $canPropose ? route('management.people.show', [$slug, $person->id, 'tree' => $tree->slug]) : null,
        ];
    }
}
