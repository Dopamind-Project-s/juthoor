<?php

namespace App\Services\Community;

use App\Models\Branch;
use App\Models\CommunityEntity;

/** Assembles an entity page from the hierarchy and the existing genealogy records, without copying either. */
class CommunityEntityPage
{
    /**
     * Phase 3 modules that will plug into every entity page through EntityContentScope. Listed so the page layout is
     * stable; none is presented as available until its module ships.
     *
     * @var array<string, array{label: string, icon: string}>
     */
    public const PLANNED_SECTIONS = [
        'history' => ['label' => 'التاريخ وأصل النشأة والهجرات', 'icon' => 'hourglass-split'],
        'regions' => ['label' => 'مناطق الإقامة والتجمع', 'icon' => 'geo-alt'],
        'diwans' => ['label' => 'الدواوين والمضافات', 'icon' => 'house-door'],
        'statistics' => ['label' => 'الأعداد والإحصاءات', 'icon' => 'bar-chart'],
        'personalities' => ['label' => 'الشخصيات والمناصب والإنجازات', 'icon' => 'person-badge'],
        'news' => ['label' => 'الأخبار والمناسبات', 'icon' => 'megaphone'],
        'archive' => ['label' => 'الأرشيف والمصادر', 'icon' => 'archive'],
    ];

    public function __construct(private readonly CommunityHierarchy $hierarchy) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CommunityEntity $entity, string $scope, bool $public): array
    {
        $nodes = $this->hierarchy->nodes($entity->family_id);
        $visibleIds = $public ? $this->hierarchy->publicIds($entity->family_id, $nodes) : array_keys($nodes);
        $ancestorIds = $this->hierarchy->ancestorIds($entity, $nodes);
        $descendantIds = array_values(array_intersect($this->hierarchy->descendantIds($entity, false, $nodes), $visibleIds));
        $entities = CommunityEntity::query()->whereIn('id', [...$ancestorIds, ...$descendantIds])->get()->keyBy('id');
        $descendants = $entities->only($descendantIds)->values();
        $branch = $entity->branch_id ? Branch::query()->when($public, fn ($query) => $query->published())->with('rootPerson', 'familyTree')->find($entity->branch_id) : null;
        $tree = $entity->familyTree ?? $branch?->familyTree;

        return [
            'ancestors' => collect($ancestorIds)->map(fn (int $id) => $entities->get($id))->filter()->values(),
            'children' => $scope === EntityContentScope::DIRECT
                ? collect($this->hierarchy->ordered($descendants->where('parent_id', $entity->id)->values()))
                : collect($this->hierarchy->ordered($descendants)),
            'directChildCount' => $descendants->where('parent_id', $entity->id)->count(),
            'descendantCount' => $descendants->count(),
            'branch' => $branch,
            'founder' => $branch?->rootPerson,
            'tree' => $tree && ($tree->is_active || ! $public) ? $tree : null,
            'completeness' => $this->completeness($entity),
            'sections' => self::PLANNED_SECTIONS,
        ];
    }

    /**
     * How complete the introductory profile is; it says nothing about the accuracy of the information.
     *
     * @return array{done: int, total: int, missing: array<int, string>}
     */
    public function completeness(CommunityEntity $entity): array
    {
        $items = [
            'نبذة تعريفية' => filled($entity->summary),
            'تعريف مفصل' => filled($entity->description),
            'ربط بشجرة أو فرع نسبي' => $entity->branch_id !== null || $entity->family_tree_id !== null,
            'تاريخ مراجعة' => $entity->reviewed_at !== null,
        ];

        return ['done' => count(array_filter($items)), 'total' => count($items), 'missing' => array_keys(array_filter($items, fn (bool $done) => ! $done))];
    }
}
