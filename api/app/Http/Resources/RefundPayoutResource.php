<?php

namespace App\Http\Resources;

use App\Models\RefundPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One refund transfer debt, for both the platform's queue and the organizer's.
 *
 * One resource rather than an admin/public pair, unlike
 * `BankAccountResource`/`PublicBankAccountResource`: every field here is
 * already known to both sides. The destination account is the ORGANIZER's own,
 * so showing it in full to them leaks nothing, and the super admin needs it to
 * make the transfer. Nothing on this row belongs to the participant beyond
 * their name, which the organizer registered.
 *
 * `awaiting_forwarding` is PUBLISHED rather than left for the client to
 * assemble from `status` + `forwarded_at` — the same call `can_request_refund`
 * makes on a race entry, and for the same reason: two readers of one rule drift,
 * and the drift here looks like a "Tandai diteruskan" button that submits into
 * a 422.
 *
 * @mixin RefundPayout
 */
class RefundPayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_label' => $this->sourceLabel(),
            'organization_id' => $this->organization_id,
            'organization_name' => $this->whenLoaded('organization', fn () => $this->organization?->name),
            'event_id' => $this->event_id,
            'event_name' => $this->whenLoaded('event', fn () => $this->event?->name),
            'amount' => (float) $this->amount,
            'recipient_name' => $this->recipient_name,
            'reason' => $this->reason,
            'status' => $this->status,

            // The organizer's registered destination, snapshotted at refund
            // time. Null throughout when they had no primary account — the
            // admin screen says so rather than printing an empty row.
            'account_type' => $this->account_type,
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'account_number' => $this->account_number,
            'account_holder' => $this->account_holder,

            // Hop one: platform → organizer.
            'proof_url' => $this->proof_url,
            'transfer_reference' => $this->transfer_reference,
            'admin_note' => $this->admin_note,
            'completed_at' => $this->completed_at,

            // Hop two: organizer → participant.
            'forwarded_proof_url' => $this->forwarded_proof_url,
            'forwarded_reference' => $this->forwarded_reference,
            'forwarded_at' => $this->forwarded_at,

            // Server's answer, not the client's arithmetic — see the docblock.
            'awaiting_transfer' => $this->isAwaitingTransfer(),
            'awaiting_forwarding' => $this->isAwaitingForwarding(),

            'created_at' => $this->created_at,
        ];
    }
}
