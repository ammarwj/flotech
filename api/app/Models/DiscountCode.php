<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
 */
class DiscountCode extends Model
{
    use HasUuids;

    /**
     * The closed value set for `discount_type`, read by DiscountCodeRequest
     * through Rule::in — same single-source pattern as Testimonial::PRESETS.
     *
     * @var list<string>
     */
    public const TYPES = ['percent', 'fixed'];

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

    /** Codes are case-insensitive to the buyer; the column holds one spelling. */
    public static function normalize(string $code): string
    {
        return Str::upper(trim($code));
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

    /**
     * What comes off `$price`. The only place either formula lives.
     *
     * Clamped to [0, $price] on both ends: a discount may zero a bill (that is
     * the point of a 100% code) but never make one negative, and a fixed code
     * worth more than the plan is a free plan, not a refund.
     */
    public function discountFor(float $price): float
    {
        $discount = $this->discount_type === 'percent'
            ? round($price * (float) $this->discount_value / 100, 2)
            : (float) $this->discount_value;

        if ($this->discount_type === 'percent' && $this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return round(max(0.0, min($discount, $price)), 2);
    }
}
