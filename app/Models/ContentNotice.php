<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A public update, correction, cancellation or schedule change on a news post or event, with the values it replaced. */
#[Fillable(['notable_type', 'notable_id', 'kind', 'message', 'previous', 'created_by'])]
class ContentNotice extends Model
{
    /** @var array<string, string> */
    public const KINDS = [
        'update' => 'تحديث',
        'correction' => 'تصحيح',
        'cancellation' => 'إلغاء',
        'rescheduled' => 'تغيير الموعد',
        'postponed' => 'تأجيل',
        'relocated' => 'تغيير المكان',
        'completed' => 'انتهاء',
        'reinstated' => 'إعادة التفعيل',
    ];

    protected function casts(): array
    {
        return ['previous' => 'array'];
    }

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
