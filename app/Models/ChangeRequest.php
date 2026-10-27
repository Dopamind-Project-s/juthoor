<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_tree_id', 'submitted_by', 'reviewed_by', 'target_type', 'target_id', 'operation', 'payload', 'base', 'base_fingerprint', 'reason', 'status', 'decision_reason', 'decided_at', 'applied_audit_id', 'assertion_id'])]
class ChangeRequest extends Model
{
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function assertion(): BelongsTo
    {
        return $this->belongsTo(Assertion::class);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'base' => 'array', 'decided_at' => 'datetime'];
    }
}
