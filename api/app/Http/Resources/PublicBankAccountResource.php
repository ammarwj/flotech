<?php

namespace App\Http\Resources;

use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organizer's account as a *buyer* needs to see it: full number, because
 * they have to type it into their banking app.
 *
 * This is deliberately not BankAccountResource, which masks the number for
 * everyone but the super admin — that masking is still right in its own
 * context (the organizer managing their payout details). Only ever return this
 * from a manual-transfer payment flow, where publishing the number *is* the
 * point; never from a listing.
 */
class PublicBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BankAccount $this */
        return [
            // The kind travels with it: a buyer paying an e-wallet is sending
            // to a phone number, and a panel that calls it "nomor rekening"
            // reads as the wrong field entirely.
            'account_type' => $this->account_type,
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'account_number' => $this->account_number,
            'account_holder' => $this->account_holder,
        ];
    }
}
