<?php

namespace App\Http\Requests\Wallet;

use App\Support\PayoutChannels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared rules for the two doors that write a payout destination, because a
 * bank account and an e-wallet differ only in how the same three columns are
 * validated — and writing that twice is how one door ends up accepting a phone
 * number the other rejects.
 *
 * `payload()` is what controllers store: it resolves the e-wallet provider key
 * into the label that actually goes in `bank_name`, and normalizes the phone
 * number, so neither lives in a controller.
 */
abstract class PayoutDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** `required` on create, `sometimes` on update — the only difference. */
    abstract protected function presence(): string;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->presence();
        $ewallet = $this->isEwallet();

        return [
            'account_type' => ['sometimes', Rule::in(PayoutChannels::TYPES)],
            // For an e-wallet this is the provider *key* (gopay, dana, …); the
            // stored value is its label — see payload().
            'bank_name' => $ewallet
                ? [$presence, Rule::in(PayoutChannels::providerKeys())]
                : [$presence, 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'account_number' => $ewallet
                // Already normalized to 08… by prepareForValidation, so the
                // error a buyer sees is about their number, not their formatting.
                ? [$presence, 'string', 'regex:/^08[0-9]{7,13}$/']
                : [$presence, 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'account_holder' => [$presence, 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bank_name.in' => 'Pilih penyedia e-wallet yang tersedia.',
            'account_number.regex' => $this->isEwallet()
                ? 'Nomor HP harus format Indonesia, contoh 081234567890.'
                : 'Nomor rekening hanya boleh berisi angka.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->isEwallet()
            ? [
                'bank_name' => 'penyedia e-wallet',
                'account_number' => 'nomor HP',
                'account_holder' => 'nama pemilik akun',
            ]
            : [
                'bank_name' => 'nama bank',
                'account_number' => 'nomor rekening',
                'account_holder' => 'nama pemilik rekening',
            ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->isEwallet() && is_string($this->input('account_number'))) {
            $this->merge([
                'account_number' => PayoutChannels::normalizePhone($this->input('account_number')),
            ]);
        }
    }

    /**
     * The row as it gets stored: provider key resolved to its label, and no
     * bank code on an e-wallet (there is no such thing to send money through).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (! $this->isEwallet()) {
            return $data;
        }

        if (isset($data['bank_name'])) {
            $data['bank_name'] = PayoutChannels::PROVIDERS[$data['bank_name']];
        }

        $data['bank_code'] = null;
        $data['account_type'] = PayoutChannels::TYPE_EWALLET;

        return $data;
    }

    protected function isEwallet(): bool
    {
        return $this->input('account_type') === PayoutChannels::TYPE_EWALLET;
    }
}
