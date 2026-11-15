<?php

namespace App\Http\Resources;

use App\Models\RaceEntry;
use App\Models\RaceEntryTransfer;
use App\Support\RegistrationForm;
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
            'emergency_contact_relation' => $this->emergency_contact_relation,
            'custom_fields' => (object) ($this->custom_fields ?? []),
            // The same answers, labelled and in the order the form asked them.
            //
            // Raw `custom_fields` above is a map of schema KEYS
            // (`status_mahasiswa_uad_umum`), which is the one thing a runner
            // reading back their own submission cannot use — and a client that
            // joined the schema itself would be the second reader of a rule the
            // server already owns. `ownAnswers()` and not `publicAnswers()`:
            // `is_public` decides what strangers see, and filtering on it here
            // would hide a runner's own emergency contact from them.
            //
            // `whenLoaded` because it needs the event's schema: the organizer's
            // list renders this resource per row and has no use for it, so the
            // cost belongs to the pages that eager-load `event`.
            'answers' => $this->whenLoaded(
                'event',
                fn () => RegistrationForm::forEvent($this->event)->ownAnswers('team', $this->custom_fields),
            ),

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
            // The discount a participant typed, if any. A snapshot: it keeps
            // reading what was actually charged even after the organizer edits
            // or deletes the code. The net price above is already net of it, so
            // the catalogue price is reconstructed as net + discount_amount —
            // the same arithmetic the PDF does.
            'discount_code' => $this->discount_code,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value === null ? null : (float) $this->discount_value,
            'discount_amount' => (float) $this->discount_amount,
            // What the runner is actually charged, on top of `price` — see
            // RaceEntry::getGrossAmountAttribute(). Zero on the manual rail,
            // which never carries a gateway or service fee.
            'gateway_fee' => (float) $this->gateway_fee,
            'gateway_tax' => (float) $this->gateway_tax,
            'service_fee' => (float) $this->service_fee,
            'gross_amount' => (float) $this->gross_amount,
            /**
             * Everything this runner has actually handed over: this entry plus
             * every settled category move.
             *
             * Published because `gross_amount` above is NOT it, and the
             * difference is visible the moment somebody moves category — an
             * entry bought at Rp 119.000 and topped up to Umum 5K still reads
             * Rp 125.440 there, beside a category it no longer matches. That
             * column is the entry's own bill and must stay frozen: the receipt
             * it backs was issued before the move. So the running total is its
             * own field rather than a rewrite, and the client never sums the
             * moves itself — that would be the second reader of
             * `paidTowards()`, which is the one place the chain is added up.
             *
             * `chargedTotal()` and not `paidTowards()`: the first answers "what
             * left my account" (fees and all), the second "how much of the
             * category price is covered" — which is what the next move's
             * difference is measured against and therefore excludes fees. A
             * card printing the second is short by exactly those fees.
             *
             * `whenLoaded` on the relation that feeds it, so the organizer's
             * list pays nothing: it renders this resource per row and has no
             * total to show.
             */
            'paid_total' => $this->whenLoaded(
                'transfers',
                fn () => RaceEntryTransfer::chargedTotal($this->resource),
            ),

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
            // Which org admin accepted the receipt. Only the approval log loads
            // the relation — a queue has nobody to name yet, and `whenLoaded`
            // is what keeps this list's N+1 off every other reader.
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier?->full_name),

            // The refund conversation. Note what is NOT here: a sixth `status`
            // value. The state is derived from these columns the same way
            // `awaiting_verification` above is derived from the proof ones, and
            // for the reason the migration gives at length — three services
            // guard on `in_array($status, [...])`.
            //
            // `can_request_refund` is the server's answer to the question the
            // button asks, published rather than reassembled on the client:
            // four conditions read twice drift, and the drift looks like a
            // button that submits into a 422. Same call as
            // `effective_payment_method`.
            // Whether this runner may move to another category, answered by the
            // server for the reason `can_request_refund` below is: four
            // conditions read twice drift, and the drift looks like a button
            // that submits into a 422.
            'can_transfer_category' => $this->canTransferCategory(),
            // The move they have started and not paid for, if any. Derived from
            // the transfers relation rather than a column on this row — see
            // RaceEntry::hasOpenTransfer() for why there is no sixth `status`
            // value. `whenLoaded` keeps the N+1 off the organizer's list, which
            // renders this resource per row and has nothing to say about moves.
            'open_transfer' => $this->whenLoaded('transfers', function () {
                $open = $this->transfers->first(fn ($t) => $t->isOpen());

                if (! $open) {
                    return null;
                }

                return [
                    'id' => $open->id,
                    'to_category' => $open->toCategory?->name,
                    'direction' => $open->direction,
                    'amount' => (float) $open->amount,
                    'gross_amount' => (float) $open->gross_amount,
                    'status' => $open->status,
                    'payment_method' => $open->payment_method,
                    'awaiting_verification' => $open->isAwaitingVerification(),
                    'payment_deadline_at' => $open->payment_deadline_at,
                    'rejected_reason' => $open->rejected_reason,
                    // Where to transfer this top-up. It has to be published
                    // HERE rather than reusing `bank_account` below: that one is
                    // gated on the ENTRY being manual and unsettled, and an
                    // entry with a top-up outstanding is fully paid — so it is
                    // always absent exactly when a manual move needs it. The
                    // rails are genuinely independent: an entry bought on the
                    // gateway can be topped up by hand if the gateway went down
                    // in between.
                    'bank_account' => $open->isManual() && ! $open->isSettled()
                        ? $this->payTo()
                        : null,
                ];
            }),
            /**
             * The moves that actually happened, oldest first.
             *
             * Published for one concrete reason: a settled top-up has its own
             * invoice and receipt, and this list is the ONLY place their
             * numbers are reachable from. Without it the documents exist at a
             * URL nobody can discover — and the entry's own receipt deliberately
             * does not mention them, because it was issued before the move and
             * must keep saying exactly what it said.
             *
             * Settled only. A pending move is `open_transfer` above, a cancelled
             * one is a thing that did not happen, and listing either here would
             * make this a log of attempts rather than of the chain the runner
             * actually paid for.
             */
            'transfer_history' => $this->whenLoaded('transfers', fn () => $this->transfers
                ->where('status', 'paid')
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'to_category' => $t->toCategory?->name,
                    'direction' => $t->direction,
                    'amount' => (float) $t->amount,
                    'paid_at' => $t->paid_at,
                    // Null on an even or downward move, which had no bill — the
                    // client shows a button per number rather than guessing
                    // from the direction.
                    'invoice_number' => $t->invoice_number,
                    'receipt_number' => $t->receipt_number,
                ])
                ->values()),
            'can_request_refund' => $this->canRequestRefund(),
            'refund_requested' => $this->refund_requested_at !== null,
            'refund_pending' => $this->hasOpenRefundRequest(),
            'refund_reason' => $this->refund_reason,
            'refund_requested_at' => $this->refund_requested_at,
            'refund_decided_at' => $this->refund_decided_at,
            // Null on an approval — `status` reads `refunded` there, and a
            // granted request needs no explanation.
            'refund_rejected_reason' => $this->refund_rejected_reason,

            // How far the money has actually travelled back, which is the one
            // thing this page could not say before: a refund is granted
            // instantly but the transfer out is manual and two-hop, so
            // "ditransfer manual" was the whole answer a runner got for a wait
            // that could run days. `null` while no payout row exists (a free
            // entry, a manual-rail one, or a credit refunded before it was
            // released) — the panel keeps its old wording there rather than
            // claiming a transfer nobody owes.
            //
            // Three strings, not the row: the payout carries the organizer's
            // own bank snapshot and the platform's internal notes, and none of
            // that is the runner's. `forwarded` is the only one that means
            // their money has left somebody's account towards them.
            'refund_transfer' => $this->whenLoaded('refundPayout', function () {
                if (! $this->refundPayout) {
                    return null;
                }

                return [
                    'stage' => $this->refundPayout->forwarded_at !== null
                        ? 'forwarded'
                        : ($this->refundPayout->status === 'completed' ? 'with_organizer' : 'queued'),
                    'forwarded_at' => $this->refundPayout->forwarded_at,
                ];
            }),

            // Where the runner must transfer. Only while a manual entry is
            // still unpaid — there is nothing to pay once it is settled.
            'bank_account' => $this->when($this->isManual() && ! $this->isSettled(), fn () => $this->payTo()),

            // The check-in credential, and `when()` is load-bearing: an unpaid
            // entry has no code, and publishing the key as null would have the
            // proof page render an empty QR frame rather than the "aktif
            // setelah lunas" placeholder. Absent reads as "not yet"; null would
            // read as "broken".
            'qr_code' => $this->when($this->qr_code !== null, fn () => $this->qr_code),
            // Null until BibService::assignNumbers() gives it one. Published
            // regardless so the page can show it the moment it exists, without
            // a second request.
            'bib_number' => $this->bib_number,
            // Whether there is an e-BIB to download, as a boolean the server
            // answers. The key itself never leaves the server (same rule as
            // `has_png` on IdCardResource), and the client never reassembles
            // this from `status`/`bib_number`: a runner can be paid and
            // numbered while the organizer has not printed yet, and a button
            // derived from those two would 404 — the `effective_payment_method`
            // bug in another shape.
            'bib_available' => $this->bib_png_key !== null,
            'bib_sent_at' => $this->bib_sent_at,
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
