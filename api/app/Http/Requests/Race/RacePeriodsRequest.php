<?php

namespace App\Http\Requests\Race;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole set of sale windows for one event, replaced in one request.
 *
 * A sync payload rather than per-row CRUD, and that is the right shape here for
 * the reason EventPersonnelService::sync() is: the list the organizer is
 * editing *is* the form on screen, all of it at once, and the windows only make
 * sense as a sequence — "does this one overlap the next" cannot be answered a
 * row at a time. (The opposite case, where a sync is wrong, is the
 * standing_adjustments ledger: two operators appending to it would overwrite
 * each other.)
 *
 * What is NOT validated here: overlaps, and whether a window that a category
 * has already sold in may still be moved. Both need the stored rows, so they
 * live in the controller — the same division ValidatesTicketDays documents.
 */
class RacePeriodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `present`, not `required`: an empty array is a meaningful payload
            // (an organizer clearing their windows before retyping them), and
            // `required` rejects it.
            'periods' => ['present', 'array', 'max:12'],
            'periods.*.id' => ['nullable', 'uuid'],
            'periods.*.name' => ['required', 'string', 'max:60'],
            'periods.*.starts_at' => ['required', 'date'],
            // after, not after_or_equal: a window that starts and ends on the
            // same instant contains nothing, and RacePricing would read it as a
            // gap — a period on screen that can never be in force.
            'periods.*.ends_at' => ['required', 'date', 'after:periods.*.starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'periods.*.ends_at.after' => 'Tanggal berakhir harus setelah tanggal mulai.',
        ];
    }
}
