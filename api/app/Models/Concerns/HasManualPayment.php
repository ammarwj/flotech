<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Shared by TicketOrder and Team — the two things a buyer can pay for — and by
 * Subscription, the one thing an organizer pays *us* for.
 *
 * There is deliberately no `awaiting_verification` status column. Both models
 * already carry a settled/unsettled status that older code guards on by value
 * (e.g. TicketService::cancel refuses `paid|cancelled|refunded`), and slipping a
 * new value into those columns would quietly walk past those guards. The state
 * is derived from the proof columns instead, and the rule lives here so the two
 * models can't drift apart.
 *
 * The three disagree on where the money state lives — `ticket_orders.status` vs
 * `teams.payment_status` (which is separate from `teams.status`, the organizer
 * admitting the team) vs `subscriptions.status` — so each names its own column,
 * and each names the value that column takes once the money has arrived.
 */
trait HasManualPayment
{
    /** The column holding this model's settled/unsettled payment state. */
    abstract protected function paymentStateColumn(): string;

    /**
     * The value of that column meaning "the money arrived". Ticket orders and
     * teams both say `paid`; a subscription says `active`, because its money
     * column *is* its lifecycle column — there is no separate payment_status.
     */
    protected function settledValue(): string
    {
        return 'paid';
    }

    public function isSettled(): bool
    {
        return $this->{$this->paymentStateColumn()} === $this->settledValue();
    }

    /** True when the payer transfers by hand, not through Midtrans. */
    public function isManual(): bool
    {
        return $this->payment_method === 'manual';
    }

    /** Proof uploaded, org admin hasn't ruled on it yet. */
    public function isAwaitingVerification(): bool
    {
        return $this->isManual()
            && ! $this->isSettled()
            && $this->payment_proof_url !== null
            && $this->verified_at === null;
    }

    /** Proof was turned down; the buyer may upload a replacement. */
    public function isProofRejected(): bool
    {
        return $this->isManual()
            && ! $this->isSettled()
            && $this->payment_proof_url === null
            && $this->rejected_reason !== null;
    }

    /** The verification queue: manual, unsettled, proof in hand. */
    public function scopeAwaitingVerification(Builder $query): Builder
    {
        return $query->where('payment_method', 'manual')
            ->where($this->paymentStateColumn(), '!=', $this->settledValue())
            ->whereNotNull('payment_proof_url')
            ->whereNull('verified_at');
    }

    /**
     * Record the payer's transfer receipt. Clearing `rejected_reason` is what
     * puts the order back in the queue after a rejection.
     */
    public function attachProof(string $url): void
    {
        $this->update([
            'payment_proof_url' => $url,
            'payment_proof_uploaded_at' => Carbon::now(),
            'rejected_reason' => null,
        ]);
    }

    /**
     * Turn the proof down and give the payer a fresh window to replace it.
     * `verified_at` stays null on purpose — that is what lets a re-upload
     * re-enter the queue; a rejection is not a verdict on the payment, only on
     * this receipt.
     */
    public function rejectProof(string $reason, Carbon $deadline): void
    {
        $this->update([
            'payment_proof_url' => null,
            'payment_proof_uploaded_at' => null,
            'rejected_reason' => $reason,
            'payment_deadline_at' => $deadline,
        ]);
    }

    public function markVerified(User $admin): void
    {
        $this->update(['verified_by' => $admin->id, 'verified_at' => Carbon::now()]);
    }
}
