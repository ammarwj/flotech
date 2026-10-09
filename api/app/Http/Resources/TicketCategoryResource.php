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
            // The only thing that says whether this category speaks about days.
            // Everything the client branches on comes from here, never from
            // whether `days` happens to be non-empty.
            'day_mode' => $this->day_mode,
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
            // The dates this category sells, each with its own capacity. The
            // buyer's day chips and their per-date "sisa" come from here, so
            // every endpoint publishing this resource has to eager-load `days`
            // — a missing relation ships an empty list and a per-day category
            // reads as one with no days on sale at all.
            'days' => $this->whenLoaded('days', fn () => $this->days->map(fn ($day) => [
                'event_date' => $day->event_date->toDateString(),
                'quota' => $day->quota,
                'sold' => $day->sold,
                'remaining' => $day->remaining($this->quota),
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
