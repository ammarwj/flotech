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
            'invoice_number' => $this->invoice_number,
            'receipt_number' => $this->receipt_number,
            'billing_cycle' => $this->billing_cycle,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'midtrans_order_id' => $this->midtrans_order_id,
            'payment_type' => $this->payment_type,
            'paid_at' => $this->paid_at,
            'plan' => new PlanResource($this->whenLoaded('plan')),
        ];
    }
}
