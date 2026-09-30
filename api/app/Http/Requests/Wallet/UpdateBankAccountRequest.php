<?php

namespace App\Http\Requests\Wallet;

use App\Models\Organization;
use App\Support\PayoutChannels;

class UpdateBankAccountRequest extends PayoutDestinationRequest
{
    protected function presence(): string
    {
        return 'sometimes';
    }

    /**
     * A partial update need not resend `account_type`, so the kind falls back to
     * the stored row's. Without this an e-wallet edited by phone number alone
     * would be validated as a rekening — digits-only passes, so the row would
     * save with no error and the phone rule would simply never have run.
     */
    protected function isEwallet(): bool
    {
        if ($this->has('account_type')) {
            return parent::isEwallet();
        }

        /** @var Organization|null $org */
        $org = $this->attributes->get('organization');
        $id = $this->route('bankAccount');

        return (bool) $org?->bankAccounts()
            ->whereKey($id)
            ->where('account_type', PayoutChannels::TYPE_EWALLET)
            ->exists();
    }
}
