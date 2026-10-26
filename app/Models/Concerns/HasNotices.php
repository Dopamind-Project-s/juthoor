<?php

namespace App\Models\Concerns;

use App\Models\ContentNotice;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Published changes stay visible: every update, correction or cancellation is a notice, newest first. */
trait HasNotices
{
    public function notices(): MorphMany
    {
        return $this->morphMany(ContentNotice::class, 'notable')->latest('id');
    }
}
