<?php

namespace App\Http\Resources;

use App\Models\DiscountCode;
use App\Services\DiscountCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DiscountCode
 */
class DiscountCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Derived, never stored. Read through the same service the checkout gate
        // uses, so the count shown here cannot disagree with the count that
        // refuses a purchase.
        $usage = app(DiscountCodeService::class)->usage($this->resource);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'max_discount' => $this->max_discount === null ? null : (float) $this->max_discount,
            'is_active' => (bool) $this->is_active,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'max_redemptions' => $this->max_redemptions,
            'once_per_organization' => (bool) $this->once_per_organization,
            'redeemed_count' => $usage['redeemed'],
            // Outstanding invoices holding a slot. They expire on their own, so
            // this is not a number the admin has to act on — it is why a quota
            // can read as full before anyone has paid.
            'reserved_count' => $usage['reserved'],
            // Empty = every plan. The admin form binds this list directly.
            'plan_ids' => $this->whenLoaded('plans', fn () => $this->plans->pluck('id')->all()),
            'created_at' => $this->created_at,
        ];
    }
}
