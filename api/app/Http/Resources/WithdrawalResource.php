<?php

namespace App\Http\Resources;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Withdrawal
 */
class WithdrawalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'organization_name' => $this->whenLoaded('organization', fn () => $this->organization?->name),
            'reference' => $this->reference,
            'amount' => (float) $this->amount,
            'admin_fee' => (float) $this->admin_fee,
            'total_debit' => (float) $this->total_debit,
            'status' => $this->status,
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'account_number' => $this->account_number,
            'account_holder' => $this->account_holder,
            'note' => $this->note,
            'proof_url' => $this->proof_url,
            'transfer_reference' => $this->transfer_reference,
            'admin_note' => $this->admin_note,
            'processed_at' => $this->processed_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
