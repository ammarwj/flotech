<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'sale_start' => ['nullable', 'date'],
            'sale_end' => ['nullable', 'date', 'after_or_equal:sale_start'],
            'benefits' => ['nullable', 'array'],
            'benefits.*' => ['string', 'max:255'],
            'is_transferable' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
