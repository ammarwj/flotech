<?php

namespace App\Http\Requests\Wallet;

class StoreBankAccountRequest extends PayoutDestinationRequest
{
    protected function presence(): string
    {
        return 'required';
    }
}
