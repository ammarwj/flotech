<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasUuids;

    /**
     * Platforms accepted in `social_links`, each with the base URL used to turn
     * a bare handle into a full profile URL. Adding one here is all it takes —
     * the request, the resources and the settings form all read this list.
     *
     * @var array<string, string>
     */
    public const SOCIAL_PLATFORMS = [
        'instagram' => 'https://instagram.com/',
        'youtube' => 'https://youtube.com/@',
        'x' => 'https://x.com/',
        'tiktok' => 'https://tiktok.com/@',
        'facebook' => 'https://facebook.com/',
    ];

    protected $fillable = [
        'name',
        'slug',
        'logo_url',
        'banner_url',
        'description',
        'contact_email',
        'contact_phone',
        'social_links',
        'custom_domain',
        'owner_id',
        'plan_id',
        'plan_expires_at',
        'storage_used_bytes',
    ];

    protected function casts(): array
    {
        return [
            'plan_expires_at' => 'datetime',
            'storage_used_bytes' => 'integer',
            'social_links' => 'array',
        ];
    }

    /**
     * Social links as a complete map — every known platform is present, with
     * `null` where the organizer hasn't filled one in, so the settings form can
     * bind to a stable shape.
     *
     * @return array<string, string|null>
     */
    public function socialLinksMap(): array
    {
        $stored = $this->social_links ?? [];

        return array_map(
            fn (string $platform) => $stored[$platform] ?? null,
            array_combine(
                array_keys(self::SOCIAL_PLATFORMS),
                array_keys(self::SOCIAL_PLATFORMS),
            ),
        );
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Whether this user runs the organization, as opposed to merely belonging
     * to it.
     *
     * Membership alone includes `operator` — the person scanning tickets at the
     * gate or typing scores at the pitch. They may record, but they don't get
     * to make things official: money endpoints refuse them (EnsureOrgAdmin),
     * and a result they save stays provisional until someone here signs it off.
     */
    public function isAdministeredBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->role === 'super_admin'
            || $this->owner_id === $user->id
            || $this->members()->where('user_id', $user->id)->where('role', 'admin')->exists();
    }

    /** How this user relates to the organization, for the dashboard to branch on. */
    public function roleOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        if ($this->owner_id === $user->id) {
            return 'owner';
        }

        if ($user->role === 'super_admin') {
            return 'admin';
        }

        return $this->members()->where('user_id', $user->id)->value('role');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function certificateTemplates(): HasMany
    {
        return $this->hasMany(CertificateTemplate::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }
}
