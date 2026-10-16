<?php

namespace App\Http\Resources;

use App\Models\RaceEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RaceEntry
 */
class RaceEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->toDateString(),
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'custom_fields' => (object) ($this->custom_fields ?? []),

            // The price snapshot, both halves. `period_name` is published
            // alongside `price` because that is what makes the number
            // checkable: the same category costs three different amounts over
            // a sale, and a receipt that does not name the window it was bought
            // in cannot be read against the price list. Neither is derived from
            // `race_period_id` — the period may have been repriced or deleted
            // since.
            'period_name' => $this->period_name,
            'price' => (float) $this->price,

            'platform_fee' => (float) $this->platform_fee,
            // What the runner is actually charged, on top of `price` — see
            // RaceEntry::getGrossAmountAttribute(). Zero on the manual rail,
            // which never carries a gateway or service fee.
            'gateway_fee' => (float) $this->gateway_fee,
            'gateway_tax' => (float) $this->gateway_tax,
            'service_fee' => (float) $this->service_fee,
            'gross_amount' => (float) $this->gross_amount,

            // Null on a free entry, which never had a bill to document. The
            // client shows a download button per number rather than guessing
            // from payment status.
            'invoice_number' => $this->invoice_number,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,

            'payment_method' => $this->payment_method,
            // The Midtrans instrument it settled with (`qris`, `gopay`, …).
            // Null for manual/free entries, and for anything settled before
            // the column existed. See the other three rails' resources.
            'payment_type' => $this->payment_type,
            // Derived, not stored — see HasManualPayment for why there is no
            // `awaiting_verification` status value.
            'awaiting_verification' => $this->isAwaitingVerification(),
            'payment_proof_url' => $this->payment_proof_url,
            'payment_proof_uploaded_at' => $this->payment_proof_uploaded_at,
            'payment_deadline_at' => $this->payment_deadline_at,
            'rejected_reason' => $this->rejected_reason,
            'verified_at' => $this->verified_at,

            // Where the runner must transfer. Only while a manual entry is
            // still unpaid — there is nothing to pay once it is settled.
            'bank_account' => $this->when($this->isManual() && ! $this->isSettled(), fn () => $this->payTo()),

            // The check-in credential, and `when()` is load-bearing: an unpaid
            // entry has no code, and publishing the key as null would have the
            // proof page render an empty QR frame rather than the "aktif
            // setelah lunas" placeholder. Absent reads as "not yet"; null would
            // read as "broken".
            'qr_code' => $this->when($this->qr_code !== null, fn () => $this->qr_code),
            // Null until somebody fills it in. Published regardless so the page
            // can show it the moment it exists, without a second request.
            'bib_number' => $this->bib_number,
            // Set the instant the desk scans them in. The runner's own page
            // shows it too — a second scan refuses, and somebody holding a
            // phone that says "sudah check-in 06:12" can stop wondering.
            'checked_in_at' => $this->checked_in_at,

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'name' => $this->event->name,
                'slug' => $this->event->slug,
                'start_date' => $this->event->start_date?->toDateString(),
                'location_name' => $this->event->location_name,
            ]),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($doc) => [
                'id' => $doc->id,
                'document_type' => $doc->document_type,
                'file_url' => $doc->file_url,
                'file_name' => $doc->file_name,
            ])->values()),
        ];
    }

    /**
     * The organizer's primary account, but only when the caller eager-loaded
     * the whole chain.
     *
     * Copied from TicketOrderResource::payTo() for the reason that one gives:
     * the organizer's participant list renders this resource for every entry,
     * so lazy-loading here would be a silent N+1. The endpoints that owe the
     * runner bank details load `event.organization.bankAccounts`.
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
