<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class AdjustWalletRequest extends FormRequest
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
            // Negative debits, positive credits. Zero would be a no-op row.
            'amount' => ['required', 'numeric', 'not_in:0'],
            'description' => ['required', 'string', 'max:500'],
        ];
    }
}
