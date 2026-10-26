<?php

namespace App\Http\Resources;

use App\Models\EventDiscountCode;
use App\Services\EventDiscountCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventDiscountCode
 */
class EventDiscountCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Derived, never stored. Read through the same service the registration
        // gate uses, so the count shown to the organizer cannot disagree with
        // the count that refuses a participant.
        $usage = app(EventDiscountCodeService::class)->usage($this->resource);

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'code' => $this->code,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'max_discount' => $this->max_discount === null ? null : (float) $this->max_discount,
            'is_active' => (bool) $this->is_active,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'max_redemptions' => $this->max_redemptions,
            'once_per_user' => (bool) $this->once_per_user,
            'redeemed_count' => $usage['redeemed'],
            // Unpaid registrations holding a quota slot. They are released when
            // the entry expires or the team is rejected/withdrawn, so this is
            // not a number the organizer has to act on — it is why a quota can
            // read as full before anyone has paid.
            'reserved_count' => $usage['reserved'],
            'created_at' => $this->created_at,
        ];
    }
}
