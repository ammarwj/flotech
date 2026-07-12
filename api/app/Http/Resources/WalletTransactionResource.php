<?php

namespace App\Http\Resources;

use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WalletTransaction
 */
class WalletTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event_name' => $this->whenLoaded('event', fn () => $this->event?->name),
            'type' => $this->type,
            'category' => $this->category,
            'status' => $this->status,
            'amount' => (float) $this->amount,
            'gross_amount' => (float) $this->gross_amount,
            'fee_amount' => (float) $this->fee_amount,
            'available_at' => $this->available_at,
            'released_at' => $this->released_at,
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }
}
