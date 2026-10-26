<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Places one media file on a content record (archive entry, diwan, narrative); the foreign key blocks deleting an attached file. */
#[Fillable(['media_item_id', 'attachable_type', 'attachable_id', 'caption', 'sort_order'])]
class MediaAttachment extends Model
{
    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
