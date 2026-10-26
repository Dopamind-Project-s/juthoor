<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Evidence for a community content record, drawn from the owner family's source library. Kept apart from genealogy
 * `citations` (tree-bound, part of shared-ancestry fingerprints). Only verified or sourced citations are shown publicly.
 */
#[Fillable(['source_id', 'citable_type', 'citable_id', 'locator', 'url', 'notes', 'verification_status', 'created_by'])]
class ContentCitation extends Model
{
    /** @var array<int, string> */
    public const PUBLIC_STATUSES = [Source::VERIFICATION_VERIFIED, Source::VERIFICATION_SOURCED];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function citable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeApproved($query)
    {
        return $query->whereIn('verification_status', self::PUBLIC_STATUSES);
    }
}
