<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\EventPlanOrder;
use App\Models\User;
use App\Notifications\PlanOrderPaid;
use App\Notifications\PlanOrderInvoiceIssued;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the lifecycle of a plan order: checkout, retrying an unpaid invoice, and
 * marking a paid one settled.
 *
 * Unlike ticket and registration payments, plan money is the platform's own
 * revenue — it never touches an organizer wallet. That holds on both rails,
 * which is why the "manual money must not touch the wallet" invariant has
 * nothing to say here.
 *
 * Numbering: an order gets its invoice number the moment it is created (an
 * unpaid invoice is still a document you can hand to finance) and its receipt
 * number only once it is actually paid.
 */
class EventPlanOrderService
{
    public function __construct(
        protected MidtransService $midtrans,
        protected PaymentRails $rails,
    ) {}

    /**
     * Create a pending plan order and open its payment. On the gateway rail
     * that is a Snap transaction (and without Midtrans credentials the order
     * settles immediately, a dev convenience); with the gateway switched off it
     * is a manual transfer to the platform's own account.
     *
     * No period is stamped. A plan is bought once for one event, so there is
     * nothing to start or expire — what the order is waiting for is an event to
     * be spent on, not a clock.
     *
     * @return array{order: EventPlanOrder, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}
     */
    public function checkout(Organization $org, Plan $plan): array
    {
        $amount = (float) $plan->price;

        // Asked *before* the row exists: a refused checkout must not burn an
        // invoice number, and nextNumber() has no way to hand one back. A
        // non-null account is itself the signal that this payment is manual.
        $bank = $this->rails->platformDestination($amount);
        $manual = $bank !== null;

        $order = $org->planOrders()->create([
            'plan_id' => $plan->id,
            'invoice_number' => $this->nextNumber('invoice'),
            'amount' => $amount,
            'status' => 'past_due', // awaiting payment; flips to paid on settlement
            'payment_method' => $manual ? 'manual' : 'gateway',
            'payment_deadline_at' => $manual ? $this->rails->deadline() : null,
        ]);

        $result = $this->start($order, $bank);

        // Only when the bill is genuinely outstanding. Without a payment gateway
        // configured openSnap() settles on the spot, and mailing "please pay"
        // about an already-paid plan would be a lie.
        if ($order->refresh()->status === 'past_due') {
            $this->mail($order, fn (EventPlanOrder $o) => new PlanOrderInvoiceIssued($o));
        }

        return $result;
    }

    /**
     * Retry payment for an unpaid invoice.
     *
     * Snap tokens expire (~24h), so rather than replaying the stored one we
     * open a fresh transaction under a new order id. The invoice number is
     * deliberately kept — the organizer still owes the same one bill. The
     * abandoned order id simply expires at Midtrans; a late webhook for it
     * finds no row and 404s, which is harmless.
     *
     * The rail is re-derived rather than replayed: a bill raised while the
     * gateway was up has to stay payable after a super admin switches it off,
     * and the other way round. RegistrationService::startPayment() already
     * rewrites payment_method on every retry for the same reason — the
     * snapshot rule means "never derived at read time", not "frozen while
     * unpaid". Nothing downstream of a plan order depends on the old value:
     * there is no wallet credit and no platform fee to keep consistent with it.
     *
     * The caller refuses this while a proof is under review, so a re-derive
     * can't strand one.
     *
     * @return array{order: EventPlanOrder, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}
     */
    public function pay(EventPlanOrder $order): array
    {
        $bank = $this->rails->platformDestination((float) $order->amount);
        $manual = $bank !== null;

        $order->update([
            'payment_method' => $manual ? 'manual' : 'gateway',
            'payment_deadline_at' => $manual ? $this->rails->deadline() : null,
        ]);

        return $this->start($order, $bank);
    }

    /**
     * Open payment on whichever rail this order is on.
     *
     * The manual branch returns before openSnap() ever runs. That is the point:
     * `mock` means "no Midtrans server key", not "paid", and a manual order
     * reaching that branch would hand out a credit nobody paid for.
     *
     * @return array{order: EventPlanOrder, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}
     */
    protected function start(EventPlanOrder $order, ?SiteSetting $bank): array
    {
        if ($bank === null) {
            return $this->openSnap($order);
        }

        return [
            'order' => $order->load('plan.features'),
            'snap_token' => null,
            'redirect_url' => null,
            'mock' => false,
            'payment_method' => 'manual',
            'bank_account' => $bank,
        ];
    }

    /**
     * @return array{order: EventPlanOrder, snap_token: string|null, redirect_url: string|null, mock: bool, payment_method: string, bank_account: SiteSetting|null}
     */
    protected function openSnap(EventPlanOrder $order): array
    {
        $org = $order->organization;

        // Newly minted ids carry PLN-; ids already at Midtrans keep their SUB-
        // prefix and still settle, because MidtransWebhookController routes plan
        // orders through its `default` arm rather than matching on the prefix.
        // Adding a PLN- arm to that match would strand every outstanding SUB-.
        $orderId = 'PLN-'.Str::upper(Str::random(10));

        $snap = $this->midtrans->createSnapTransaction(
            ['order_id' => $orderId, 'gross_amount' => (int) round((float) $order->amount)],
            ['first_name' => $org->name, 'email' => $org->contact_email],
            rtrim((string) config('app.frontend_url'), '/').'/organizer/billing?status=success',
        );

        $order->update([
            'midtrans_order_id' => $orderId,
            'midtrans_token' => $snap['token'],
        ]);

        if ($snap['mock']) {
            // No payment gateway configured — settle immediately for dev.
            $this->activate($order);
        }

        return [
            'order' => $order->load('plan.features'),
            'snap_token' => $snap['token'],
            'redirect_url' => $snap['redirect_url'],
            'mock' => $snap['mock'],
            'payment_method' => 'gateway',
            'bank_account' => null,
        ];
    }

    /**
     * Mark a plan order paid.
     *
     * Nothing is written to the organization: a paid order is a *credit*, and it
     * entitles nothing until an event spends it (EventController::store). The
     * event does not exist yet at this point — the organizer pays first and
     * creates the event afterwards — so there is nothing here to bind it to.
     *
     * Midtrans re-delivers webhooks, so this must be safe to run twice: an
     * already-issued receipt number is never reissued.
     */
    public function activate(EventPlanOrder $order, ?string $paymentType = null): void
    {
        // A receipt is only issued once, and so is the email carrying it. Without
        // this, a re-delivered webhook lands a second "you paid" in the inbox for
        // one payment.
        $alreadyPaid = $order->status === 'paid';

        $order->update([
            'status' => 'paid',
            'paid_at' => $order->paid_at ?? Carbon::now(),
            'receipt_number' => $order->receipt_number ?? $this->nextNumber('receipt'),
            'payment_type' => $paymentType ?? $order->payment_type,
        ]);

        if (! $alreadyPaid) {
            $this->mail($order, fn (EventPlanOrder $o) => new PlanOrderPaid($o));
        }
    }

    /**
     * The organizer uploads their transfer receipt for a super admin to check.
     *
     * @throws PaymentException
     */
    public function submitProof(EventPlanOrder $order, string $proofUrl): void
    {
        if (! $order->isManual()) {
            throw new PaymentException('Tagihan ini tidak dibayar lewat transfer manual.');
        }

        if ($order->status !== 'past_due') {
            throw new PaymentException('Tagihan ini sudah tidak menunggu pembayaran.');
        }

        $order->attachProof($proofUrl);
    }

    /**
     * Accept a manual transfer into the platform's own account, which activates
     * the plan. Approving is the whole payment — no money moves on its own —
     * so this is super_admin work, never an organizer's.
     *
     * @throws PaymentException
     */
    public function approveProof(EventPlanOrder $order, User $admin): void
    {
        if (! $order->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $order->markVerified($admin);
        $this->activate($order->fresh(), 'manual_transfer');
    }

    /**
     * @throws PaymentException
     */
    public function rejectProof(EventPlanOrder $order, string $reason, Carbon $deadline): void
    {
        if (! $order->isAwaitingVerification()) {
            throw new PaymentException('Tidak ada bukti pembayaran yang menunggu verifikasi.');
        }

        $order->rejectProof($reason, $deadline);
    }

    /**
     * Mail the organization's owner. Swallows its own errors: the money has moved
     * and the plan is applied, so a PDF render or queue hiccup must not bubble
     * into the Midtrans webhook and provoke a retry.
     *
     * @param  callable(EventPlanOrder): Notification  $make
     */
    protected function mail(EventPlanOrder $order, callable $make): void
    {
        try {
            $order->organization->owner?->notify(
                $make($order->load(['plan', 'organization']))
            );
        } catch (Throwable $e) {
            Log::error('Gagal mengirim email pembelian paket', [
                'plan_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Next document number for the current month, e.g. INV/2026/07/0001.
     *
     * The sequence restarts each month. Concurrent checkouts are serialized by
     * locking the month's rows; the unique index on the column is the backstop.
     *
     * @param  'invoice'|'receipt'  $kind
     */
    public function nextNumber(string $kind): string
    {
        $column = $kind === 'receipt' ? 'receipt_number' : 'invoice_number';
        $prefix = config("billing.{$kind}_prefix", $kind === 'receipt' ? 'KW' : 'INV');
        $period = Carbon::now()->format('Y/m');

        return DB::transaction(function () use ($column, $prefix, $period) {
            // Postgres rejects FOR UPDATE alongside an aggregate ("FOR UPDATE is
            // not allowed with aggregate functions"), so take the highest row and
            // lock *that* rather than locking a max(). Sequences are zero-padded,
            // so lexical order is numeric order.
            $last = EventPlanOrder::where($column, 'like', "{$prefix}/{$period}/%")
                ->orderByDesc($column)
                ->lockForUpdate()
                ->value($column);

            $seq = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

            return sprintf('%s/%s/%04d', $prefix, $period, $seq);
        });
    }
}
