<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['family_id', 'source_type', 'title', 'reference', 'file_path', 'url', 'published_at', 'notes', 'verification_status'])]
class Source extends Model
{
    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_SOURCED = 'sourced';

    public const VERIFICATION_PENDING_REVIEW = 'pending_review';

    public const VERIFICATION_UNRESOLVED = 'unresolved';

    protected function casts(): array
    {
        return ['published_at' => 'date'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'person_sources')
            ->withPivot(['id', 'relation_type', 'notes'])
            ->withTimestamps();
    }
}
