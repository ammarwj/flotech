<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * The discount arithmetic, shared by the two code tables.
 *
 * Two tables, deliberately: `discount_codes` is plan-keyed and owned by a super
 * admin, `event_discount_codes` is event-keyed and owned by the organizer, and
 * their usage is counted from different tables entirely. What they must NOT have
 * two of is the formula — a second copy drifts, and the drift shows up as a
 * preview quoting one number and the till charging another.
 *
 * Anything that differs (who owns the row, which scope axis it has, what counts
 * as a redemption) stays on the model.
 */
trait HasDiscountFormula
{
    /**
     * The closed value set for `discount_type`, read through Rule::in by both
     * form requests — same single-source pattern as Testimonial::PRESETS.
     *
     * @var list<string>
     */
    public const TYPES = ['percent', 'fixed'];

    /** Codes are case-insensitive to the buyer; the column holds one spelling. */
    public static function normalize(string $code): string
    {
        return Str::upper(trim($code));
    }

    /**
     * What comes off `$price`. The only place either formula lives.
     *
     * Clamped to [0, $price] on both ends: a discount may zero a bill (that is
     * the point of a 100% code) but never make one negative, and a fixed code
     * worth more than the thing being bought is a free one, not a refund.
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
