<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Portal branding of one family: the editable draft and the published copy visitors see. */
#[Fillable(['family_id', 'draft', 'published', 'draft_updated_by', 'draft_updated_at', 'published_by', 'published_at'])]
class FamilyPortalSetting extends Model
{
    protected function casts(): array
    {
        return ['draft' => 'array', 'published' => 'array', 'draft_updated_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draft !== null && $this->draft !== $this->published;
    }
}
