<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone trusted with part of ONE event's day-to-day running, without a seat
 * in `organization_members`. See the create_event_operators_table migration
 * for why the four permission columns are fixed booleans rather than a
 * catalogue.
 *
 * Independent of `EventPersonnel` (referees/staff) — a user may be both for
 * the same event, and neither table knows the other exists.
 */
class EventOperator extends Model
{
    use HasUuids;

    /**
     * The four independent permission categories. Fixed and known by name to
     * `EnsureOperatorPermission`'s four aliases — not a dynamic catalogue,
     * because the middleware has to be able to name them in route files.
     *
     * "All akses" in the UI is a derived shortcut that checks all four; it is
     * never a fifth column.
     *
     * @var list<string>
     */
    public const PERMISSIONS = ['ticketing', 'scheduling', 'registration', 'documents'];

    protected $fillable = [
        'event_id',
        'user_id',
        'can_ticketing',
        'can_scheduling',
        'can_registration',
        'can_documents',
        'invited_by',
    ];

    protected function casts(): array
    {
        return [
            'can_ticketing' => 'boolean',
            'can_scheduling' => 'boolean',
            'can_registration' => 'boolean',
            'can_documents' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Whether this operator holds the given category (`'ticketing'`, …). */
    public function has(string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS, true)
            && (bool) $this->{'can_'.$permission};
    }
}
