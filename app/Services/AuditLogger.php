<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\Person;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function record(?User $user, ?Family $family, Model $record, string $action, ?array $before, ?array $after): AuditLog
    {
        return AuditLog::query()->create([
            'reviewers_only' => $record instanceof CrossFamilyLink
                || ($record instanceof Source && $record->citations()->whereNotNull('cross_family_link_id')->exists())
                || ($record instanceof Citation && $record->cross_family_link_id !== null),
            'user_id' => $user?->id,
            'family_id' => $family?->id,
            'auditable_type' => $record->getMorphClass(),
            'auditable_id' => $record->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /** @return array<string, mixed> */
    public function snapshot(Model $record): array
    {
        $snapshot = collect($record->getAttributes())->except(['created_at', 'updated_at', 'password', 'remember_token', 'token_hash'])->all();
        if ($record instanceof Person) {
            $snapshot['attribute_ids'] = $record->personAttributes()->orderBy('person_attributes.id')->pluck('person_attributes.id')->all();
        }

        return $snapshot;
    }
}
