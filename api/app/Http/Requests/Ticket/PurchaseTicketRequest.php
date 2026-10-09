<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseTicketRequest extends FormRequest
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
            // Which days the buyer attends. Required only for a `per_day`
            // category, and ignored for a pass — enforced in the controller,
            // for the same reason `payment_channel` below is.
            'dates' => ['nullable', 'array', 'max:60'],
            'dates.*' => ['date_format:Y-m-d'],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_email' => ['required', 'email', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
            'holder_names' => ['nullable', 'array'],
            'holder_names.*' => ['nullable', 'string', 'max:255'],
            // Required only when the order turns out to be a paid gateway one —
            // enforced in the controller, which is the only place that knows.
            'payment_channel' => ['nullable', 'string'],
        ];
    }
}
