<?php

namespace App\Http\Resources;

use App\Models\RaceCategory;
use App\Support\RacePricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RaceCategory
 */
class RaceCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pricing = app(RacePricing::class);

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'quota' => $this->quota,
            'taken' => $this->taken,
            'remaining' => $this->remaining(),
            'is_active' => $this->is_active,
            // Only the half that belongs to the category: active, with room.
            // Whether anything is on sale *right now* is `current_price` below
            // being non-null, because that is the question that needs the
            // event's windows — folding them into one boolean would make two
            // fields answer one question and let them disagree.
            'is_open' => $this->isOpen(),
            // What a runner would be charged if they registered this instant,
            // or null when nothing is on sale for this category — a gap
            // between windows, a window this category has no price row for, or
            // no windows at all. The public form reads exactly this rather
            // than picking a row out of `prices` itself: a client that chose
            // its own period is the `effective_payment_method` bug again.
            'current_price' => $this->currentPrice($pricing),
            // Every window with this category's price in it, unsold ones
            // included as null. Needs `prices` and `event.racePeriods`
            // eager-loaded — a missing relation reports an empty schedule,
            // which reads as a category with no prices rather than one whose
            // relation was forgotten. Same trap as `plan.features`.
            'price_schedule' => $pricing->schedule($this->resource),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Null rather than 0: zero is a real price (a sponsored free run), so a
     * fallback of 0 would make "free" and "not on sale" indistinguishable on
     * the one field the form reads to decide whether to show a button.
     */
    private function currentPrice(RacePricing $pricing): ?float
    {
        $period = $pricing->periodAt($this->resource->event);

        return $period === null ? null : $pricing->priceFor($this->resource, $period);
    }
}
