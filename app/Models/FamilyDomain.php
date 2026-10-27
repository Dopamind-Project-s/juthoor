<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A host mapped to one family. Only an active mapping is served, and only while host routing is enabled. */
#[Fillable(['family_id', 'host', 'kind', 'status', 'verification_token', 'notes', 'requested_by', 'verified_at', 'verified_by', 'activated_at', 'activated_by', 'disabled_at'])]
class FamilyDomain extends Model
{
    public const KIND_SUBDOMAIN = 'subdomain';

    public const KIND_CUSTOM = 'custom';

    public const STATUS_PENDING = 'pending_verification';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_PENDING => 'بانتظار التحقق',
        self::STATUS_VERIFIED => 'تم التحقق',
        self::STATUS_ACTIVE => 'مفعّل',
        self::STATUS_DISABLED => 'معطّل',
    ];

    protected $hidden = ['verification_token'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'activated_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** DNS TXT record proving control of a custom domain. */
    public function verificationRecord(): string
    {
        return 'juthoor-verify='.$this->verification_token;
    }
}
