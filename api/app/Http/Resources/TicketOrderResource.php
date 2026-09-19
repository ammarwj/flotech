<?php

namespace App\Http\Resources;

use App\Models\TicketOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketOrder
 */
class TicketOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'buyer_name' => $this->buyer_name,
            'buyer_email' => $this->buyer_email,
            'buyer_phone' => $this->buyer_phone,
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total_price' => (float) $this->total_price,
            'platform_fee' => (float) $this->platform_fee,
            // What the buyer is actually charged, on top of total_price — see
            // TicketOrder::getGrossAmountAttribute(). Zero for manual-transfer
            // orders, which never carry a gateway/service fee.
            'gateway_fee' => (float) $this->gateway_fee,
            'gateway_tax' => (float) $this->gateway_tax,
            'service_fee' => (float) $this->service_fee,
            'gross_amount' => (float) $this->gross_amount,
            // Null on a free order, which never had a bill to document. The
            // client shows a download button per number rather than guessing
            // from payment status.
            'invoice_number' => $this->invoice_number,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'payment_method' => $this->payment_method,
            // Derived, not stored — see HasManualPayment for why there is no
            // `awaiting_verification` status value.
            'awaiting_verification' => $this->isAwaitingVerification(),
            'payment_proof_url' => $this->payment_proof_url,
            'payment_proof_uploaded_at' => $this->payment_proof_uploaded_at,
            'payment_deadline_at' => $this->payment_deadline_at,
            'rejected_reason' => $this->rejected_reason,
            'verified_at' => $this->verified_at,
            // Where the buyer must transfer. Only while a manual order is still
            // unpaid — there is nothing to pay once it's settled.
            'bank_account' => $this->when($this->isManual() && ! $this->isSettled(), fn () => $this->payTo()),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'name' => $this->event->name,
                'start_date' => $this->event->start_date?->toDateString(),
                'location_name' => $this->event->location_name,
            ]),
            'tickets' => TicketResource::collection($this->whenLoaded('tickets')),
        ];
    }

    /**
     * The organizer's primary account, but only when the caller eager-loaded the
     * whole chain. The organizer's buyer list renders this same resource for
     * every order, so lazy-loading here would be a silent N+1 — the endpoints
     * that owe the buyer bank details load `event.organization.bankAccounts`.
     */
    private function payTo(): ?PublicBankAccountResource
    {
        if (! $this->relationLoaded('event')
            || ! $this->event?->relationLoaded('organization')
            || ! $this->event->organization?->relationLoaded('bankAccounts')) {
            return null;
        }

        $account = $this->event->organization->bankAccounts->firstWhere('is_primary', true);

        return $account ? new PublicBankAccountResource($account) : null;
    }
}
