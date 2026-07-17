<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Mail\TicketPurchasedMail;
use App\Models\Organization;
use App\Models\TicketCategory;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ticket order lifecycle: platform-fee calculation, purchase (reserving quota
 * and issuing the individual QR tickets), settlement and cancellation.
 *
 * Tickets are issued at purchase time but only become valid for check-in once
 * their order is paid — see ScanController.
 */
class TicketService
{
    public function __construct(protected PlanGate $gate, protected WalletService $wallet) {}

    /**
     * Platform fee for an order amount, based on the organizer plan's
     * `ticket_fee_percent` feature (0 when unset).
     */
    public function platformFee(Organization $org, float $amount): float
    {
        $percent = (float) ($this->gate->value($org, 'ticket_fee_percent') ?? 0);

        return round($amount * $percent / 100, 2);
    }

    /**
     * Create a pending order, reserve the quota and issue one QR ticket per
     * seat. Runs in a transaction so quota and tickets stay consistent.
     *
     * `$paymentMethod` is snapshotted rather than resolved later: the gateway
     * can be switched back on at any time, and an order taken during an outage
     * stays a manual one for the rest of its life. `$deadline` only applies to
     * manual orders — nothing else releases their reserved quota.
     *
     * @param  array{buyer_name: string, buyer_email: string, buyer_phone?: string|null, quantity: int}  $buyer
     * @param  list<string|null>  $holderNames
     */
    public function purchase(TicketCategory $category, array $buyer, array $holderNames, float $platformFee, string $orderId, ?string $userId, string $paymentMethod = 'gateway', ?Carbon $deadline = null): TicketOrder
    {
        $quantity = (int) $buyer['quantity'];
        $unitPrice = (float) $category->price;

        return DB::transaction(function () use ($category, $buyer, $holderNames, $platformFee, $orderId, $userId, $quantity, $unitPrice, $paymentMethod, $deadline) {
            $category->increment('sold', $quantity);

            $order = $category->orders()->create([
                'event_id' => $category->event_id,
                'buyer_user_id' => $userId,
                'buyer_name' => $buyer['buyer_name'],
                'buyer_email' => $buyer['buyer_email'],
                'buyer_phone' => $buyer['buyer_phone'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
                'platform_fee' => $platformFee,
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'payment_deadline_at' => $deadline,
                'midtrans_order_id' => $orderId,
            ]);

            for ($i = 0; $i < $quantity; $i++) {
                $order->tickets()->create([
                    'ticket_category_id' => $category->id,
                    'event_id' => $category->event_id,
                    'qr_code' => 'TIX-'.Str::lower(Str::ulid()),
                    'holder_name' => $holderNames[$i] ?? $buyer['buyer_name'],
                ]);
            }

            return $order;
        });
    }

    /**
     * Settle a paid order, credit the organizer's wallet and email the buyer
     * their e-ticket link. Idempotent — re-delivered webhooks are no-ops (the
     * early return is also what keeps the buyer from getting a second mail),
     * and the wallet guards itself besides.
     */
    public function markPaid(TicketOrder $order): void
    {
        if ($order->status === 'paid') {
            return;
        }

        DB::transaction(function () use ($order) {
            $order->update(['status' => 'paid', 'paid_at' => Carbon::now()]);

            // A manual transfer went straight into the organizer's own bank
            // account — the money never passed through us, so crediting the
            // wallet would make their balance claim funds we are not holding.
            // Same reasoning as the offline team entry in RegistrationController.
            if (! $order->isManual()) {
                $this->wallet->creditTicketOrder($order->load('event.organization'));
            }
        });

        $this->sendPurchaseConfirmation($order);
    }

    /**
     * Queue the buyer's confirmation mail. Deliberately swallows its own
     * errors: the payment is already settled, so a mail/queue hiccup must not
     * bubble up into the Midtrans webhook and provoke a retry.
     */
    protected function sendPurchaseConfirmation(TicketOrder $order): void
    {
        try {
            Mail::to($order->buyer_email)->queue(
                new TicketPurchasedMail($order->load(['event', 'category']))
            );
        } catch (Throwable $e) {
            Log::error('Gagal mengirim email konfirmasi tiket', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The buyer uploads their transfer receipt for an org admin to check.
     *
     * @throws PaymentException
     */
    public function submitProof(TicketOrder $order, string $proofUrl): void
    {
        if (! $order->isManual()) {
            throw new PaymentException('Pesanan ini tidak dibayar lewat transfer manual.');
        }

        if ($order->status !== 'pending') {
            throw new PaymentException('Pesanan ini sudah tidak menunggu pembayaran.');
        }

        $order->attachProof($proofUrl);
    }

    /**
     * Accept a manual transfer. markPaid() is what keeps this off the wallet —
     * the money went to the organizer's own account, not ours.
     *
     * @throws PaymentException
     */
    public function approveProof(TicketOrder $order, User $admin): void
    {
        if (! $order->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $order->markVerified($admin);
        $this->markPaid($order->fresh());
    }

    /**
     * @throws PaymentException
     */
    public function rejectProof(TicketOrder $order, string $reason, Carbon $deadline): void
    {
        if (! $order->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $order->rejectProof($reason, $deadline);
    }

    /**
     * Void a *paid* order: release its quota and tickets. The wallet reversal
     * is RefundService's job. `cancel()` can't be reused — it deliberately
     * refuses paid orders.
     */
    public function refund(TicketOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update(['status' => 'refunded']);
            $order->category()->decrement('sold', $order->quantity);
            $order->tickets()->delete();
        });
    }

    /**
     * Cancel an unpaid order: release its reserved quota and void its tickets.
     */
    public function cancel(TicketOrder $order, string $status = 'cancelled'): void
    {
        if (in_array($order->status, ['paid', 'cancelled', 'refunded'], true)) {
            return;
        }

        DB::transaction(function () use ($order, $status) {
            $order->update(['status' => $status]);
            $order->category()->decrement('sold', $order->quantity);
            $order->tickets()->delete();
        });
    }
}
