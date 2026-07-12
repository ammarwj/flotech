<?php

namespace App\Http\Resources;

use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankAccount
 */
class BankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The full number is only ever needed by the super admin making the
        // transfer; the organizer just needs to recognise their own account.
        $isSuperAdmin = auth('api')->user()?->role === 'super_admin';

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'account_number' => $isSuperAdmin ? $this->account_number : $this->maskedNumber(),
            'account_holder' => $this->account_holder,
            'is_primary' => $this->is_primary,
            'created_at' => $this->created_at,
        ];
    }
}
