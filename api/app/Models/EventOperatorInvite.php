<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invite to become an {@see EventOperator}, addressed to an email address.
 * `EventOperator` rows are only ever created by `accept()` on a usable one.
 */
class EventOperatorInvite extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
    ];

    protected $fillable = [
        'event_id',
        'email',
        'can_ticketing',
        'can_scheduling',
        'can_registration',
        'can_documents',
        'status',
        'token',
        'expires_at',
        'accepted_at',
        'invited_by',
        'accepted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'can_ticketing' => 'boolean',
            'can_scheduling' => 'boolean',
            'can_registration' => 'boolean',
            'can_documents' => 'boolean',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Still clickable: pending status and not past its expiry. */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }

    /** @return array<int, string> */
    public function permissions(): array
    {
        return array_values(array_filter(
            EventOperator::PERMISSIONS,
            fn (string $permission) => (bool) $this->{'can_'.$permission}
        ));
    }
}
