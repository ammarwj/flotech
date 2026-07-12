<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBankAccountRequest extends FormRequest
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
            'bank_name' => ['sometimes', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'account_number' => ['sometimes', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'account_holder' => ['sometimes', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_number.regex' => 'Nomor rekening hanya boleh berisi angka.',
        ];
    }
}
