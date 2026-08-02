<?php

namespace App\Http\Resources;

use App\Models\SiteSetting;
use App\Models\EventPlanOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventPlanOrder
 */
class EventPlanOrderResource extends JsonResource
{
    /**
     * The platform's account is one row shared by every order in the response,
     * so it is resolved once per request rather than per row — same memoization
     * PlanResource uses for feature definitions, and for the same reason: both
     * /plan-orders and /admin/plan-orders render collections.
     */
    private static ?SiteSetting $platformAccount = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'plan_id' => $this->plan_id,
            'invoice_number' => $this->invoice_number,
            'receipt_number' => $this->receipt_number,
            'amount' => (float) $this->amount,
            'status' => $this->status,

            // The consumption ledger. A paid order with `event_id: null` is a
            // credit still waiting to be spent — the client filters on exactly
            // this pair rather than calling a second endpoint for them.
            'event_id' => $this->event_id,
            'consumed_at' => $this->consumed_at,

            // Upgrades. `upgrade_of_id` marks a top-up bill — it is never a
            // credit, however paid it looks. `superseded` marks the order a paid
            // top-up has replaced, which is likewise no longer spendable; both
            // have to reach the client or the credit list would count one
            // purchase twice.
            'upgrade_of_id' => $this->upgrade_of_id,
            'superseded' => $this->isSuperseded(),
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'name' => $this->event->name,
            ]),

            'midtrans_order_id' => $this->midtrans_order_id,
            'payment_type' => $this->payment_type,
            'paid_at' => $this->paid_at,
            'plan' => new PlanResource($this->whenLoaded('plan')),

            // Manual transfer. `awaiting_verification` is derived, not stored —
            // HasManualPayment explains why there is no status value for it.
            'payment_method' => $this->payment_method,
            'awaiting_verification' => $this->isAwaitingVerification(),
            'payment_proof_url' => $this->payment_proof_url,
            'payment_proof_uploaded_at' => $this->payment_proof_uploaded_at,
            'payment_deadline_at' => $this->payment_deadline_at,
            'rejected_reason' => $this->rejected_reason,
            'verified_at' => $this->verified_at,
            // Where to transfer, only while the bill is manual and unpaid.
            'bank_account' => $this->when(
                $this->isManual() && ! $this->isSettled(),
                fn () => new PlatformBankAccountResource(static::platformAccount()),
            ),
            // The super admin queue lists rows across every organization.
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ]),
        ];
    }

    private static function platformAccount(): SiteSetting
    {
        return static::$platformAccount ??= SiteSetting::current();
    }

    /** Drop the per-request memo. Tests that edit site_settings mid-request need it. */
    public static function flushPlatformAccount(): void
    {
        static::$platformAccount = null;
    }
}
