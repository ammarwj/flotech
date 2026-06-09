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
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_email' => ['required', 'email', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
            'holder_names' => ['nullable', 'array'],
            'holder_names.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
