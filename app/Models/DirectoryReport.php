<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A report about incorrect or inappropriate directory information, handled by family administrators. */
#[Fillable(['member_profile_id', 'reporter_id', 'reason', 'details'])]
class DirectoryReport extends Model
{
    /** @var array<string, string> */
    public const REASONS = ['incorrect' => 'معلومات غير صحيحة', 'impersonation' => 'انتحال شخصية', 'inappropriate' => 'محتوى غير لائق', 'privacy' => 'كشف بيانات خاصة', 'other' => 'سبب آخر'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class, 'member_profile_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
