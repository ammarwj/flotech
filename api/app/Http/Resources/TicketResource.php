<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'qr_code' => $this->qr_code,
            'holder_name' => $this->holder_name,
            // Which day this QR admits its holder, or null when the category
            // does not sell by the day.
            'event_date' => $this->event_date?->toDateString(),
            'is_used' => $this->is_used,
            'used_at' => $this->used_at,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
        ];
    }
}
