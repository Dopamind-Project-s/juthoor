<?php

namespace App\Services\Genealogy;

use App\Models\Branch;
use App\Models\Person;
use DomainException;

class BranchFocusService
{
    public const MODE_FOCUS = 'focus';

    public const MODE_ISOLATED = 'isolated';

    public function __construct(private readonly GenealogyTraversalService $traversal) {}

    /**
     * @return array{branch: Branch, mode: string, nodes: array<int, array{person: Person, state: string}>}
     */
    public function getBranchFocus(int $branchId, string $mode = self::MODE_FOCUS, ?int $depth = null): array
    {
        if (! in_array($mode, [self::MODE_FOCUS, self::MODE_ISOLATED], true)) {
            throw new DomainException("Unsupported branch focus mode [{$mode}].");
        }

        $branch = Branch::query()->findOrFail($branchId);

        if ($branch->root_person_id === null) {
            throw new DomainException('A branch focus requires a confirmed founder.');
        }

        $path = $this->traversal->getAncestorPath($branch->root_person_id);
        $pathIds = $path->modelKeys();
        $descendantIds = $this->traversal->getDescendantIds($branch->root_person_id, $depth);
        $sideIds = $mode === self::MODE_FOCUS
            ? $this->traversal->getSideBranches($pathIds, $depth)
            : [];
        $visibleIds = array_values(array_unique([...$pathIds, ...$descendantIds, ...$sideIds]));
        $people = Person::query()->whereKey($visibleIds)->get()->keyBy('id');
        $descendants = array_fill_keys($descendantIds, true);
        $ancestors = array_fill_keys($pathIds, true);
        $sides = array_fill_keys($sideIds, true);
        $nodes = [];

        foreach ($visibleIds as $personId) {
            $state = match (true) {
                $personId === $branch->root_person_id => 'branch_root',
                isset($descendants[$personId]) => 'branch_descendant',
                isset($ancestors[$personId]) => 'ancestor_path',
                isset($sides[$personId]) => 'dimmed',
                default => 'hidden',
            };

            $nodes[] = ['person' => $people->get($personId), 'state' => $state];
        }

        return ['branch' => $branch, 'mode' => $mode, 'nodes' => $nodes];
    }
}
