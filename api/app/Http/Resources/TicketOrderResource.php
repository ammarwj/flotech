<?php

namespace App\Http\Resources;

use App\Models\TicketOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketOrder
 */
class TicketOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'buyer_name' => $this->buyer_name,
            'buyer_email' => $this->buyer_email,
            'buyer_phone' => $this->buyer_phone,
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) $this->total_price,
            'platform_fee' => (float) $this->platform_fee,
            'status' => $this->status,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'name' => $this->event->name,
                'start_date' => $this->event->start_date?->toDateString(),
                'location_name' => $this->event->location_name,
            ]),
            'tickets' => TicketResource::collection($this->whenLoaded('tickets')),
        ];
    }
}
