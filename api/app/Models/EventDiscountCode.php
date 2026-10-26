<?php

namespace App\Models;

use App\Models\Concerns\HasDiscountFormula;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discount code a participant may type while registering for ONE event.
 *
 * The participant-facing twin of DiscountCode, and the differences are the
 * reason it is a second table rather than a scope column on the first:
 *
 * - **Event-keyed, organizer-owned.** `discount_codes` is plan-keyed and only a
 *   super admin edits it. Folding both into one row means a nullable scope
 *   column plus a polymorphic pivot — the nullable-pair shape this codebase
 *   keeps refusing (see the `official_match_stats` invariant).
 * - **Usage is counted from two other tables** (`teams`, `race_entries`), never
 *   from `event_plan_orders`.
 * - **No scope axis at all.** A code applies to every category of its event;
 *   there is no `discount_code_plan` equivalent, so there is also no "empty
 *   pivot means everything" reading to maintain.
 *
 * What IS shared is the arithmetic — see HasDiscountFormula.
 *
 * There is no redeemed counter here. Usage is derived by
 * EventDiscountCodeService; a stored count would need one writer for "used" and
 * several for "released" (the Midtrans webhook, race-entries:expire-manual, a
 * team withdrawing), and the drift between them is invisible until a code
 * reports itself exhausted having never been used.
 */
class EventDiscountCode extends Model
{
    use HasDiscountFormula, HasUuids;

    protected $fillable = [
        'event_id',
        'code',
        'description',
        'discount_type',
        'discount_value',
        'max_discount',
        'is_active',
        'starts_at',
        'ends_at',
        'max_redemptions',
        'once_per_user',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_redemptions' => 'integer',
            'once_per_user' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
