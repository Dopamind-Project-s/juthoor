<?php

namespace App\Models;

use App\Notifications\QueuedPasswordReset;
use App\Notifications\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'username', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmail, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedPasswordReset($token));
    }

    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Family::class, 'family_memberships')
            ->withPivot(['id', 'role'])->withTimestamps();
    }

    public function familyMemberships(): HasMany
    {
        return $this->hasMany(FamilyMembership::class);
    }

    public function roleFor(Family|int $family): ?string
    {
        $familyId = $family instanceof Family ? $family->id : $family;

        return $this->familyMemberships()->where('family_id', $familyId)->value('role');
    }

    public function canEditFamily(Family|int $family): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->is_super_admin && $this->is_active) {
            return true;
        }

        return in_array($this->roleFor($family), [
            FamilyMembership::ROLE_EDITOR,
            FamilyMembership::ROLE_ADMIN,
        ], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin && $this->is_active;
    }
}
