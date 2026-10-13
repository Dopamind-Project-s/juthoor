<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['family_tree_id', 'uploaded_by', 'committed_by', 'checksum', 'file_path', 'status', 'state_fingerprint', 'results', 'created_ids', 'decision_reason', 'failure_reason', 'committed_at'])]
class ImportBatch extends Model
{
    protected function casts(): array
    {
        return ['results' => 'array', 'created_ids' => 'array', 'committed_at' => 'datetime'];
    }
}
