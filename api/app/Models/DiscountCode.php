<?php

namespace App\Models;

use App\Models\Concerns\HasDiscountFormula;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount an organizer may type at plan checkout.
 *
 * Only checkout: `plan-orders/{order}/upgrade` deliberately takes no code. An
 * upgrade is priced as "target price minus everything paid so far"
 * (EventPlanOrder::paidTowardsPlan), so a discount given at checkout is made
 * back on the first upgrade and total spend lands on the target plan's price —
 * which is the promise that method exists to keep.
 *
 * There is no redeemed counter on this row. Usage is derived from
 * event_plan_orders by DiscountCodeService; a stored count would need one writer
 * for "paid" and two for "released" (the Midtrans webhook and
 * plan-orders:expire-manual), and the drift between them is invisible until a
 * code reports itself exhausted having never been used.
 *
 * The arithmetic lives in HasDiscountFormula, shared with EventDiscountCode —
 * the participant-facing twin of this row. Everything else is deliberately not
 * shared; see that trait's docblock.
 */
class DiscountCode extends Model
{
    use HasDiscountFormula, HasUuids;

    protected $fillable = [
        'code',
        'description',
        'discount_type',
        'discount_value',
        'max_discount',
        'is_active',
        'starts_at',
        'ends_at',
        'max_redemptions',
        'once_per_organization',
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
            'once_per_organization' => 'boolean',
        ];
    }

    /**
     * The plans this code applies to — an EMPTY relation means every plan.
     *
     * "No rows" has to read as unrestricted rather than as "applies to nothing":
     * a code with no restriction is the common case, and the alternative would
     * make every code require a pivot row per plan and silently stop working the
     * day a new plan is added.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'discount_code_plan');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(EventPlanOrder::class);
    }

    /** True when this code is open to every plan in the catalogue. */
    public function appliesToEveryPlan(): bool
    {
        return $this->plans()->doesntExist();
    }
}
