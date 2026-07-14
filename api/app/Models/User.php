<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'full_name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'role',
        'default_mode',
        'is_verified',
        'email_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_verified' => 'boolean',
            'password' => 'hashed',
        ];
    }

    // ---- JWTSubject (RS256) ----

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Custom claims embedded in every access token.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role,
        ];
    }

    // ---- Email verification ----

    public function getEmailForVerification(): string
    {
        return $this->email;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'is_verified' => true,
        ])->save();
    }

    // ---- Password reset ----

    /**
     * Overrides the framework's English ResetPassword notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    // ---- Relationships ----

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(UserRefreshToken::class);
    }

    public function ownedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'owner_id');
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function managedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'manager_user_id');
    }
}
