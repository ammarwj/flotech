<?php

namespace App\Http\Requests\Race\Concerns;

/**
 * The price matrix rules, shared by the store and update requests so the two
 * doors cannot accept a shape the other refuses.
 *
 * What is deliberately NOT here: whether each `race_period_id` belongs to this
 * category's event, and whether a period whose price is being removed has
 * already been sold in. Both need the stored rows and the event, so they live
 * in RaceCategoryController::syncPrices() — the same division
 * ValidatesTicketDays documents for the ticket days.
 */
trait ValidatesRacePrices
{
    /**
     * @param  'required'|'sometimes'  $presence
     * @return array<string, mixed>
     */
    protected function priceRules(string $presence): array
    {
        return [
            // A category with no price rows is legal and means "not on sale in
            // any period yet" — an organizer setting up before the windows
            // exist. So `present` rather than `min:1` even on create.
            'prices' => [$presence === 'required' ? 'present' : 'sometimes', 'array', 'max:12'],
            'prices.*.race_period_id' => ['required', 'uuid'],
            // min:0, because a free fun run is a real thing (a sponsored
            // community run). It is `required` rather than nullable on purpose:
            // "not sold in this period" is said by leaving the row out
            // entirely, never by sending a null price. See the migration — a
            // null here would be the one thing that makes an absent row and a
            // present-but-empty row mean the same, which is exactly what that
            // invariant forbids.
            'prices.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
