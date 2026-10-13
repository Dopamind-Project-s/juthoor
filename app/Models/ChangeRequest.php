<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['family_tree_id', 'submitted_by', 'reviewed_by', 'target_type', 'target_id', 'operation', 'payload', 'base', 'base_fingerprint', 'reason', 'status', 'decision_reason', 'decided_at', 'applied_audit_id'])]
class ChangeRequest extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'array', 'base' => 'array', 'decided_at' => 'datetime'];
    }
}
