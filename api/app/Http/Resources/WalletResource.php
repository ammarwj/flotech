<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use App\Services\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'balance_available' => (float) $this->balance_available,
            'balance_pending' => (float) $this->balance_pending,
            'balance_on_hold' => $this->onHold(),
            // Derived, not settings — hence beside balance_on_hold rather than
            // in `rules`. Published so the UI reads the server's answer instead
            // of recomputing a rule that already lives here (the same mistake
            // `effective_payment_method` exists to prevent).
            'balance_minimum_waived' => $this->minimumWaivedBalance(),
            'max_withdrawable' => max(0.0, round((float) $this->balance_available - PlatformSettings::adminFee(), 2)),
            'total_earned' => (float) $this->total_earned,
            'total_withdrawn' => (float) $this->total_withdrawn,
            'has_bank_account' => $this->organization->bankAccounts()->where('is_primary', true)->exists(),
            'has_active_withdrawal' => $this->withdrawals()->whereIn('status', ['pending', 'processing'])->exists(),

            // The UI must never hardcode these — a super admin can change them.
            'rules' => [
                'minimum_withdrawal' => PlatformSettings::minimumWithdrawal(),
                'admin_fee' => PlatformSettings::adminFee(),
            ],
        ];
    }
}
