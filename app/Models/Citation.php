<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_id', 'family_tree_id', 'person_id', 'person_relationship_id', 'field_key', 'locator', 'url', 'notes', 'verification_status'])]
class Citation extends Model
{
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(PersonRelationship::class, 'person_relationship_id');
    }
}
