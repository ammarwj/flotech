<?php

namespace App\Http\Requests\Ticket\Concerns;

use App\Models\TicketCategory;
use App\Services\TicketService;
use Illuminate\Validation\Rule;

/**
 * The day-mode rules, written once for both category doors.
 *
 * Store and Update are separate classes whose other rules genuinely differ
 * (`required` vs `sometimes`), but these two do not — and two copies would mean
 * one door accepting a mode the other rejects. Same reasoning as
 * SquadRules::validationRules() feeding three forms.
 *
 * Note what is *not* here: whether `dates` may be empty, and whether they fall
 * inside the event. Both need the event and the stored row, so they live in the
 * controller's syncDays() — see there.
 */
trait ValidatesTicketDays
{
    /**
     * @return array<string, mixed>
     */
    protected function dayRules(): array
    {
        return [
            'day_mode' => ['sometimes', Rule::in(TicketCategory::DAY_MODES)],
            // Same ceiling the two ticket doors enforce: a category selling more
            // days than an order may cover would offer a pass nobody can buy.
            'dates' => ['nullable', 'array', 'max:'.TicketService::MAX_DAYS_PER_ORDER],
            'dates.*' => ['date_format:Y-m-d'],
        ];
    }
}
