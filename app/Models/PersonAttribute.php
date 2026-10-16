<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['family_id', 'key', 'label', 'color', 'is_active'])]
class PersonAttribute extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'person_attribute_assignments')->withTimestamps();
    }

    public function scopeAvailableFor($query, int $familyId)
    {
        return $query->where(fn ($query) => $query->whereNull('family_id')->orWhere('family_id', $familyId));
    }

    public function getTextColorAttribute(): string
    {
        $hex = ltrim($this->color, '#');
        $brightness = (hexdec(substr($hex, 0, 2)) * 299 + hexdec(substr($hex, 2, 2)) * 587 + hexdec(substr($hex, 4, 2)) * 114) / 1000;

        return $brightness > 150 ? '#111111' : '#ffffff';
    }
}
