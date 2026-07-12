<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Models\Team;
use App\Models\TicketOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin refunds. Voids the order and reverses the organizer's credit.
 *
 * This does NOT return money to the buyer — the platform holds it in its own
 * Midtrans account, so the refund must also be issued there. The admin UI says
 * so explicitly.
 */
class RefundService
{
    public function __construct(protected TicketService $tickets, protected WalletService $wallet) {}

    public function refundTicketOrder(TicketOrder $order, ?User $actor = null, ?string $reason = null): void
    {
        if ($order->status !== 'paid') {
            throw new WalletException('Hanya pesanan lunas yang bisa direfund.');
        }

        if ($order->tickets()->where('is_used', true)->exists()) {
            throw new WalletException('Ada tiket yang sudah dipakai check-in, pesanan ini tidak bisa direfund.');
        }

        DB::transaction(function () use ($order, $actor, $reason) {
            $this->tickets->refund($order);
            $this->reverse('ticket_order', $order->id, 'ticket_sale', $actor, $reason);
        });
    }

    public function refundTeam(Team $team, ?User $actor = null, ?string $reason = null): void
    {
        if ($team->payment_status !== 'paid') {
            throw new WalletException('Hanya pendaftaran lunas yang bisa direfund.');
        }

        if ((float) $team->payment_amount <= 0) {
            throw new WalletException('Pendaftaran gratis tidak bisa direfund.');
        }

        DB::transaction(function () use ($team, $actor, $reason) {
            $team->update(['payment_status' => 'refunded']);
            $this->reverse('team', $team->id, 'registration_fee', $actor, $reason);
        });
    }

    protected function reverse(string $sourceType, string $sourceId, string $category, ?User $actor, ?string $reason): void
    {
        $credit = WalletTransaction::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('category', $category)
            ->first();

        // No credit exists for a free order, or one refunded before the wallet
        // shipped — voiding the order is still the right outcome.
        if ($credit) {
            $this->wallet->reverseCredit($credit, $actor, $reason);
        }
    }
}
