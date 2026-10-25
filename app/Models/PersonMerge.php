<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of one person merge. The journal lists every row-level change (update with from/to, deleted row,
 * inserted row) so the merge can be reversed exactly; only the undo fields change afterwards.
 */
#[Fillable(['family_tree_id', 'survivor_id', 'merged_id', 'performed_by', 'reason', 'fingerprint', 'decisions', 'journal', 'status', 'undone_by', 'undone_at', 'undo_reason'])]
class PersonMerge extends Model
{
    protected function casts(): array
    {
        return ['decisions' => 'array', 'journal' => 'array', 'undone_at' => 'datetime'];
    }

    public function survivor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'survivor_id')->withoutGlobalScope('active');
    }

    public function merged(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'merged_id')->withoutGlobalScope('active');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }
}
