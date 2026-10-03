<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function record(User $user, ?Family $family, Model $record, string $action, ?array $before, ?array $after): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => $user->id,
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
        return collect($record->getAttributes())->except(['created_at', 'updated_at'])->all();
    }
}
