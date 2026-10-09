<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A box-office sale: the organizer issuing a ticket on the buyer's behalf.
 *
 * Deliberately the same shape as PurchaseTicketRequest — the staff fills in the
 * same form the buyer would have. Nothing more: the rail is the event's, and
 * the controller derives it.
 */
class SellTicketRequest extends FormRequest
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
            'ticket_category_id' => ['required', 'string'],
            // How many *people*, not how many ticket prices: a per-day order
            // multiplies this by the days picked. The controller derives the
            // paid-unit count, because only it knows the category's day mode.
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'dates' => ['nullable', 'array', 'max:60'],
            'dates.*' => ['date_format:Y-m-d'],
            'buyer_name' => ['required', 'string', 'max:255'],
            // Still required, as at the public door: the e-ticket and its
            // receipt are mailed there, and a walk-in buyer who gives no address
            // ends up holding a QR they cannot find again.
            'buyer_email' => ['required', 'email', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
            'holder_names' => ['nullable', 'array'],
            'holder_names.*' => ['nullable', 'string', 'max:255'],
            // No `rail` field, deliberately. Which rail a box-office sale takes
            // is PaymentRails::methodFor()'s answer about this event, not a
            // choice the till makes: an event selling through the gateway must
            // not be able to take cash off the books, and one that cannot sell
            // online has nothing but cash. Accepting it here would be a second
            // reader of "global switch AND event rail" — the same shape of bug
            // as two readers of `stage`.
            //
            // Required only for a paid gateway sale — enforced in the
            // controller, which is the only place that knows the total.
            'payment_channel' => ['nullable', 'string'],
        ];
    }
}
