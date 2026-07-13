<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_monthly',
        'price_yearly',
        'yearly_discount_percent',
        'is_active',
        'is_public',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'yearly_discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * `price_yearly` is derived, never typed in: the discount is the only knob a
     * super admin turns. Keeping the billed column a function of that percentage
     * is what stops the UI from promising a discount Midtrans never applies —
     * SubscriptionService::checkout() charges price_yearly as-is.
     *
     * Rounded to the nearest thousand so prices stay presentable in rupiah.
     */
    public static function computeYearlyPrice(float $monthly, float $discountPercent): float
    {
        $full = $monthly * 12 * (1 - $discountPercent / 100);

        return round($full / 1000) * 1000;
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
