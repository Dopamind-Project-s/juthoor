<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use App\Models\EntityContentLink;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared placement logic for every content module (news, events, diwans, archive…) on entity pages (v2.0 chapter 6).
 * Records are linked, never copied: subject links roll up to ancestor pages, audience links flow down to descendant pages.
 * Placement is only the first filter; the module's own publication and audience rules apply on top.
 */
class EntityContentScope
{
    public const DIRECT = 'direct';

    public const WITH_DESCENDANTS = 'descendants';

    /** @var array<string, string> */
    public const MODES = [self::WITH_DESCENDANTS => 'مع الفروع التابعة', self::DIRECT => 'هذا المستوى فقط'];

    public function __construct(private readonly CommunityHierarchy $hierarchy) {}

    /**
     * Direct: records about this entity or addressed to it. With descendants: records about the entity or any descendant,
     * plus records addressed to the entity or any ancestor. A sibling's records never appear. $eligibleIds limits both sets
     * (for visitors: the publicly visible entities). Filtering the content table by key returns each record once,
     * however many of its links match.
     *
     * @param  array<int, int>|null  $eligibleIds
     */
    public function apply(Builder $query, CommunityEntity $entity, string $mode, ?array $eligibleIds = null): Builder
    {
        if (! isset(self::MODES[$mode])) {
            throw new DomainException('Unknown content scope mode.');
        }
        $nodes = $this->hierarchy->nodes($entity->family_id);
        [$subjects, $audiences] = $mode === self::DIRECT
            ? [[$entity->id], [$entity->id]]
            : [$this->hierarchy->descendantIds($entity, true, $nodes), [...$this->hierarchy->ancestorIds($entity, $nodes), $entity->id]];
        if ($eligibleIds !== null) {
            $subjects = array_values(array_intersect($subjects, $eligibleIds));
            $audiences = array_values(array_intersect($audiences, $eligibleIds));
        }

        $model = $query->getModel();

        return $query->whereIn($model->getQualifiedKeyName(), EntityContentLink::query()->select('linkable_id')
            ->where('linkable_type', $model->getMorphClass())
            ->where(fn ($links) => $links
                ->where(fn ($link) => $link->where('relation', EntityContentLink::RELATION_SUBJECT)->whereIn('community_entity_id', $subjects))
                ->orWhere(fn ($link) => $link->where('relation', EntityContentLink::RELATION_AUDIENCE)->whereIn('community_entity_id', $audiences))));
    }

    /** Idempotent; a record links only to entities of its owner's family. */
    public function link(Model $record, CommunityEntity $entity, string $relation): EntityContentLink
    {
        if (! in_array($relation, [EntityContentLink::RELATION_SUBJECT, EntityContentLink::RELATION_AUDIENCE], true)) {
            throw new DomainException('Unknown content link relation.');
        }
        $owner = $record->getAttribute('owner_entity_id') ? CommunityEntity::query()->find($record->getAttribute('owner_entity_id')) : null;
        if (! $owner || (int) $owner->family_id !== (int) $entity->family_id) {
            throw new DomainException('Content links only to entities of its owner family.');
        }

        return EntityContentLink::query()->firstOrCreate([
            'community_entity_id' => $entity->id, 'linkable_type' => $record->getMorphClass(), 'linkable_id' => $record->getKey(), 'relation' => $relation,
        ]);
    }
}
