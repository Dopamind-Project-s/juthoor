<?php

namespace App\Models\Concerns;

use App\Models\MediaAttachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Content that shows files from the media library; the files are attached, never copied. */
trait HasMediaAttachments
{
    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'attachable')->orderBy('sort_order')->orderBy('id');
    }
}
