<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class CompleteWithdrawalRequest extends FormRequest
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
            // Proof of the manual bank transfer. Required — a payout marked
            // done with no receipt is unauditable.
            'proof_url' => ['required', 'string', 'url', 'max:2048'],
            'transfer_reference' => ['nullable', 'string', 'max:100'],
            'admin_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
