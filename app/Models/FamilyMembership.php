<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_id', 'user_id', 'role'])]
class FamilyMembership extends Model
{
    public const ROLE_READER = 'reader';

    public const ROLE_EDITOR = 'editor';

    public const ROLE_ADMIN = 'admin';

    /** @return array<int, string> */
    public static function roles(): array
    {
        return [self::ROLE_READER, self::ROLE_EDITOR, self::ROLE_ADMIN];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
