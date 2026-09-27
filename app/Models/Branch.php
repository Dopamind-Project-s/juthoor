<?php

namespace App\Models;

use App\Services\Genealogy\BranchIntegrityService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['family_tree_id', 'parent_branch_id', 'root_person_id', 'branch_type', 'name_ar', 'name_en', 'slug', 'description', 'membership_mode', 'sort_order', 'is_active', 'verification_status'])]
class Branch extends Model
{
    public const MEMBERSHIP_DESCENDANTS = 'descendants';

    public const TYPE_FAMILY_GROUP = 'family_group';

    protected static function booted(): void
    {
        static::saving(function (Branch $branch): void {
            app(BranchIntegrityService::class)->validate($branch);
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function familyTree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class);
    }

    public function parentBranch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_branch_id');
    }

    public function childBranches(): HasMany
    {
        return $this->hasMany(self::class, 'parent_branch_id');
    }

    public function rootPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'root_person_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
