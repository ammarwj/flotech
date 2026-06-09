<?php

namespace App\Http\Resources;

use App\Models\TicketCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketCategory
 */
class TicketCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) $this->price,
            'quota' => $this->quota,
            'sold' => $this->sold,
            'remaining' => $this->remaining(),
            'sale_start' => $this->sale_start,
            'sale_end' => $this->sale_end,
            'benefits' => $this->benefits ?? [],
            'is_transferable' => $this->is_transferable,
            'is_active' => $this->is_active,
            'is_on_sale' => $this->isOnSale(),
            'created_at' => $this->created_at,
        ];
    }
}
