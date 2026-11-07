<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Models\Organization;
use App\Models\RefundPayout;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\RefundAwaitingTransfer;
use App\Notifications\RefundTransferCompleted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The only thing allowed to move a refund payout through its two hops.
 *
 * See the migration for the invariants this enforces. The one worth repeating
 * at the call site: this service NEVER touches the wallet. A refund has already
 * pulled the organizer's credit back, and a payout row is the record of a
 * transfer happening OUTSIDE the ledger — the same call `RegistrationService`
 * makes for manual buyer transfers, where money that never passed through the
 * platform has no business being in it. There is no `WalletService` dependency
 * here on purpose, so the rule is held by what cannot be reached rather than by
 * a comment.
 */
class RefundPayoutService
{
    /**
     * Record that a refund left somebody owed money, if anybody is.
     *
     * Called by `RefundService` inside the transaction that reverses the
     * credit, so a payout row and the reversal it reads either both exist or
     * neither does.
     *
     * > **A null reversal writes NOTHING, and that is the whole guard.** Three
     * > cases reach a refund with no money having moved through the platform: a
     * > free registration, one paid by manual transfer straight into the
     * > organizer's own account, and a credit still `pending` when it was
     * > refunded — which `reverseCredit()` cancels in place, with no debit,
     * > because it was never withdrawable in the first place. Writing a row for
     * > any of them puts a transfer debt in front of a super admin for money
     * > the platform does not hold and the organizer never received. Test it by
     * > COMPARING a gateway refund with a manual one on the same event:
     * > asserting "a row exists for the gateway refund" alone passes even when
     * > every refund writes one.
     *
     * Idempotent through the unique `(source_type, source_id)` index rather
     * than a read-then-write, the same backstop `WalletService::record()` leans
     * on: a retried refund must not queue a second transfer.
     */
    public function record(
        string $sourceType,
        string $sourceId,
        Organization $organization,
        ?string $eventId,
        ?WalletTransaction $reversal,
        string $recipientName,
        ?string $reason = null,
    ): ?RefundPayout {
        // Nothing left the platform, so nothing has to come back to it. See the
        // docblock above for the three cases that land here.
        if (! $reversal) {
            return null;
        }

        if ($existing = $this->findBySource($sourceType, $sourceId)) {
            return $existing;
        }

        // The organizer's own registered destination, snapshotted. Null when
        // they have none: the debt is real either way, and a queue that refuses
        // to record it would lose the only trace that money is owed. The admin
        // screen says the account is missing instead.
        $bank = $organization->bankAccounts()->where('is_primary', true)->first();

        try {
            $payout = RefundPayout::create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'organization_id' => $organization->id,
                'event_id' => $eventId,
                'wallet_transaction_id' => $reversal->id,
                // Read from the ledger row, never recomputed — see the
                // migration's first invariant.
                'amount' => $reversal->amount,
                'recipient_name' => $recipientName,
                'reason' => $reason,
                'status' => 'pending',
                'account_type' => $bank?->account_type ?? 'bank',
                'bank_name' => $bank?->bank_name,
                'bank_code' => $bank?->bank_code,
                'account_number' => $bank?->account_number,
                'account_holder' => $bank?->account_holder,
            ]);
        } catch (QueryException $e) {
            // Lost a race to the unique index: the debt is already recorded.
            if ($existing = $this->findBySource($sourceType, $sourceId)) {
                return $existing;
            }
            throw $e;
        }

        $this->notifyPlatform($payout);

        return $payout;
    }

    /**
     * Hop one: the platform transferred to the organizer, with proof.
     *
     * Guarded by value rather than by "not completed" so a replayed request is
     * a no-op instead of overwriting the first transfer's proof with the
     * second's — the same reading `activate()` makes for a resent Midtrans
     * webhook.
     *
     * @throws WalletException
     */
    public function complete(
        RefundPayout $payout,
        User $actor,
        string $proofUrl,
        ?string $transferReference = null,
        ?string $adminNote = null,
    ): RefundPayout {
        if ($payout->status === 'completed') {
            throw new WalletException('Transfer refund ini sudah ditandai selesai.');
        }

        $payout->update([
            'status' => 'completed',
            'proof_url' => $proofUrl,
            'transfer_reference' => $transferReference,
            'admin_note' => $adminNote,
            'completed_by' => $actor->id,
            'completed_at' => Carbon::now(),
        ]);

        $this->notifyOrganizer($payout->fresh());

        return $payout->fresh();
    }

    /**
     * Hop two: the organizer forwarded it to their own participant.
     *
     * Refuses a row the platform has not paid yet. Without that an organizer
     * could mark a refund forwarded before receiving it, and the participant's
     * page would say their money was sent by somebody who did not have it.
     *
     * @throws WalletException
     */
    public function markForwarded(
        RefundPayout $payout,
        User $actor,
        string $proofUrl,
        ?string $reference = null,
    ): RefundPayout {
        if ($payout->status !== 'completed') {
            throw new WalletException('Dana refund ini belum ditransfer platform, jadi belum ada yang bisa diteruskan.');
        }

        if ($payout->forwarded_at !== null) {
            throw new WalletException('Refund ini sudah ditandai diteruskan ke peserta.');
        }

        $payout->update([
            'forwarded_proof_url' => $proofUrl,
            'forwarded_reference' => $reference,
            'forwarded_by' => $actor->id,
            'forwarded_at' => Carbon::now(),
        ]);

        return $payout->fresh();
    }

    private function findBySource(string $sourceType, string $sourceId): ?RefundPayout
    {
        return RefundPayout::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * Tell the super admins a transfer is owed.
     *
     * Sent after the surrounding transaction commits (`afterCommit`), because a
     * mail about a refund that then rolled back is worse than a late one. The
     * notification is queued, so a NEW WORKER IMAGE is required — not a
     * restart; `worker` and `scheduler` have no volume mount, and a stale
     * container fails with `__PHP_Incomplete_Class`.
     */
    private function notifyPlatform(RefundPayout $payout): void
    {
        $admins = User::where('role', 'super_admin')->get();

        if ($admins->isEmpty()) {
            return;
        }

        DB::afterCommit(fn () => Notification::send($admins, new RefundAwaitingTransfer($payout)));
    }

    /**
     * Tell the organizer the money is on its way to them, so they can answer
     * the participant who is asking — and forward it.
     */
    private function notifyOrganizer(RefundPayout $payout): void
    {
        $owner = $payout->organization?->owner;

        if (! $owner) {
            return;
        }

        $owner->notify(new RefundTransferCompleted($payout));
    }
}
