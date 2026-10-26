<?php

namespace App\Services\Genealogy;

use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\Source;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-checks approved shared-ancestry links after genealogy writes.
 * Only links touching the changed families are checked, once per committed transaction,
 * instead of fingerprinting every approved link on every saved row. Families left pending by a
 * rolled-back transaction are re-checked on the next commit, which is conservative and harmless.
 */
class CrossFamilyLinkInvalidator
{
    /** @var array<int|string, true> */
    private array $pendingFamilies = [];

    private ?bool $hasTable = null;

    public function __construct(private readonly CrossFamilyLinkService $links) {}

    public function changed(Model $record): void
    {
        $this->hasTable = $this->hasTable ?: Schema::hasTable('cross_family_links');
        // Evidence for a recorded claim (before and after the write) is not part of any link's dependencies.
        $claimEvidence = $record instanceof Citation && $record->assertion_id !== null && ($record->wasRecentlyCreated || $record->getOriginal('assertion_id') !== null);
        if (! $this->hasTable || $claimEvidence) {
            return;
        }

        $this->pendingFamilies[$this->familyKey($record)] = true;
        DB::afterCommit(fn () => $this->flush());
    }

    public function flush(): void
    {
        $families = array_keys($this->pendingFamilies);
        $this->pendingFamilies = [];
        if ($families === []) {
            return;
        }

        $memo = [];
        CrossFamilyLink::query()->where('status', 'approved')
            ->when(! in_array('*', $families, true), fn ($query) => $query->where(fn ($query) => $query->whereIn('left_family_id', $families)->orWhereIn('right_family_id', $families)))
            ->get()
            // Not a short arrow: each() stops at a callback returning false, i.e. at the first invalid link.
            ->each(function (CrossFamilyLink $link) use (&$memo): void {
                $this->links->current($link, $memo);
            });
    }

    /** A record whose family cannot be resolved (e.g. a site-wide attribute) re-checks every link. */
    private function familyKey(Model $record): int|string
    {
        $treeFamily = fn (?int $treeId) => $treeId ? FamilyTree::query()->whereKey($treeId)->value('family_id') : null;

        $familyId = match (true) {
            $record instanceof Family => $record->id,
            $record instanceof FamilyTree, $record instanceof Source, $record instanceof PersonAttribute => $record->family_id,
            $record instanceof Person, $record instanceof PersonRelationship => $treeFamily($record->family_tree_id),
            $record instanceof Citation => $record->cross_family_link_id ? null : $treeFamily($record->family_tree_id),
            default => null,
        };

        return $familyId ?? '*';
    }
}
