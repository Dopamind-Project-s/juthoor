<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A functional role held through one family membership; removing the membership removes the assignment. */
#[Fillable(['family_membership_id', 'role_id', 'assigned_by'])]
class FamilyRoleAssignment extends Model
{
    public function membership(): BelongsTo
    {
        return $this->belongsTo(FamilyMembership::class, 'family_membership_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
