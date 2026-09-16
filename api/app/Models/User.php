<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
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
        // `must_change_password` is deliberately absent: it is a lock, and a
        // lock that mass assignment can open is not one. It is written only by
        // the officiating provisioning (setting it) and by updatePassword()
        // (clearing it), both through forceFill — the same posture `password`
        // and `remember_token` already take.
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
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
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

    /** Events this account is a referee or match staff on. */
    public function personnelAssignments(): HasMany
    {
        return $this->hasMany(EventPersonnel::class);
    }

    // ---- Jenis akun ----

    /**
     * Jenis akun yang **diturunkan dari data**, bukan kolom.
     *
     * `default_mode` tidak bisa dipakai untuk ini: itu cuma topi terakhir yang
     * dipakai user di switcher dashboard (dan defaultnya 'organizer' untuk
     * setiap akun baru), jadi ia menjawab "mau lihat apa" — bukan "dia siapa".
     * Yang menjawab "dia siapa" adalah jejaknya: punya/anggota organisasi =
     * organizer, mendaftarkan tim = peserta.
     *
     * Satu akun bisa keduanya (organizer yang juga ikut turnamen orang lain),
     * jadi hasilnya list, bukan satu nilai. List kosong = akun baru yang belum
     * melakukan apa pun.
     *
     * Membaca relasi yang sudah dimuat — pemanggilnya wajib menyiapkan ketiganya
     * lewat `load()` **atau** `loadExists()` (lihat `hasAccountContext()`).
     * Layar admin memang butuh barisnya; shell auth cuma butuh ada/tidak, dan
     * memuat `teams` yang lebar untuk pertanyaan itu lebih mahal dari jawabannya.
     *
     * @return list<string>
     */
    public function accountTypes(): array
    {
        $types = [];

        if ($this->hasAny('ownedOrganizations') || $this->hasAny('organizationMemberships')) {
            $types[] = 'organizer';
        }

        if ($this->hasAny('managedTeams')) {
            $types[] = 'participant';
        }

        return $types;
    }

    /**
     * Ketiga relasi di atas sudah siap dibaca `accountTypes()` — lewat `load()`
     * (barisnya ada) atau `loadExists()` (cuma ada/tidak).
     *
     * Ini gerbang `UserResource::account_types`. Tanpa gerbang, "tidak dimuat"
     * tak bisa dibedakan dari "tidak punya" dan setiap organizer akan terkirim
     * sebagai akun kosong — persis kesalahan yang membuat mode switcher-nya
     * hilang.
     */
    public function hasAccountContext(): bool
    {
        return $this->accountRelationLoaded('ownedOrganizations')
            && $this->accountRelationLoaded('organizationMemberships')
            && $this->accountRelationLoaded('managedTeams');
    }

    private function accountRelationLoaded(string $relation): bool
    {
        return $this->relationLoaded($relation)
            || array_key_exists(self::existsKey($relation), $this->attributes);
    }

    /** Ada isinya, apa pun bentuk yang dimuat pemanggil. */
    private function hasAny(string $relation): bool
    {
        if ($this->relationLoaded($relation)) {
            return $this->getRelation($relation)->isNotEmpty();
        }

        return (bool) $this->getAttribute(self::existsKey($relation));
    }

    /** `loadExists()` menulis kolomnya snake_case, seperti `withCount`. */
    private static function existsKey(string $relation): string
    {
        return Str::snake($relation).'_exists';
    }
}
