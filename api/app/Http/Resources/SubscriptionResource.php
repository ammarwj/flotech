<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'plan_id' => $this->plan_id,
            'billing_cycle' => $this->billing_cycle,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'midtrans_order_id' => $this->midtrans_order_id,
            'paid_at' => $this->paid_at,
            'plan' => new PlanResource($this->whenLoaded('plan')),
        ];
    }
}
