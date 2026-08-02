<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Exceptions\PlanFeatureException;
use App\Models\Event;
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
        protected PlanGate $gate,
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
     * Raise a top-up bill that moves `$order` onto a bigger plan.
     *
     * The organizer pays the difference, so buying Starter and upgrading costs
     * exactly what buying Pro would have — there is no cheaper route in, and no
     * reason to sit on a plan that has stopped fitting.
     *
     * The difference is measured against everything paid so far along the
     * upgrade chain (see paidTowardsPlan()), not against this order's own
     * `amount`. Two upgrades in a row would otherwise overcharge: the second
     * would be priced against the first top-up alone. An order created by
     * `events:backfill-plan` contributes 0 and therefore pays full price, which
     * is right — no money ever changed hands for it.
     *
     * @throws PlanFeatureException
     */
    public function checkoutUpgrade(EventPlanOrder $order, Plan $target): array
    {
        if ($order->status !== 'paid') {
            throw new PlanFeatureException('Paket ini belum lunas, jadi belum bisa di-upgrade.', ['feature' => 'plan_upgrade_unpaid']);
        }

        if ($order->isSuperseded()) {
            throw new PlanFeatureException('Paket ini sudah di-upgrade sebelumnya.', ['feature' => 'plan_already_upgraded']);
        }

        if ($order->plan_id === $target->id) {
            throw new PlanFeatureException('Event ini sudah memakai paket tersebut.', ['feature' => 'plan_unchanged']);
        }

        // Invariant 1: the target has to grant everything the current plan does.
        // This is what refuses a downgrade — and it also refuses a *dearer* plan
        // that happens to drop a feature, which a price comparison would wave
        // through. See PlanGate::planCovers().
        if (! $this->gate->planCovers($order->plan, $target)) {
            throw new PlanFeatureException(
                'Paket itu tidak mencakup semua fitur paket yang sekarang, jadi bukan upgrade.',
                ['feature' => 'plan_not_superset'],
            );
        }

        $difference = round((float) $target->price - $order->paidTowardsPlan(), 2);

        if ($difference <= 0) {
            throw new PlanFeatureException('Paket itu tidak lebih mahal, jadi tidak bisa di-upgrade ke sana.', ['feature' => 'plan_not_upgrade']);
        }

        // An unpaid attempt is reopened rather than duplicated, the same reason
        // pay() exists: two live bills for one upgrade would let the organizer
        // settle both and pay twice for a single move.
        $pending = $order->upgrades()->where('status', 'past_due')->latest()->first();

        if ($pending && $pending->plan_id === $target->id) {
            return $this->pay($pending);
        }

        $bank = $this->rails->platformDestination($difference);
        $manual = $bank !== null;

        $upgrade = $order->organization->planOrders()->create([
            'plan_id' => $target->id,
            'upgrade_of_id' => $order->id,
            'invoice_number' => $this->nextNumber('invoice'),
            'amount' => $difference,
            'status' => 'past_due',
            'payment_method' => $manual ? 'manual' : 'gateway',
            'payment_deadline_at' => $manual ? $this->rails->deadline() : null,
        ]);

        $result = $this->start($upgrade, $bank);

        if ($upgrade->refresh()->status === 'past_due') {
            $this->mail($upgrade, fn (EventPlanOrder $o) => new PlanOrderInvoiceIssued($o));
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

        if ($order->upgrade_of_id) {
            $this->applyUpgrade($order);
        }

        if (! $alreadyPaid) {
            $this->mail($order, fn (EventPlanOrder $o) => new PlanOrderPaid($o));
        }
    }

    /**
     * Hand the entitlement over from the order being upgraded to this one.
     *
     * The successor becomes the holder — of the event if there was one, of the
     * credit if there wasn't — and the old order retires. That is what collapses
     * the two cases ("event already running" and "credit not spent yet") into
     * one path: the only difference is whether there is an `event_id` to move.
     *
     * The old order keeps its own `plan_id`, `amount`, invoice and receipt
     * untouched. Its invoice still truthfully reads "Starter, Rp 150.000"; this
     * order's reads "Pro, Rp 200.000". Nothing has to be snapshotted to keep
     * either document honest, because neither is ever rewritten.
     *
     * Runs under activate(), so it must survive a re-delivered webhook: every
     * write here is the same value the second time round.
     */
    private function applyUpgrade(EventPlanOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $previous = EventPlanOrder::whereKey($order->upgrade_of_id)->lockForUpdate()->first();

            if (! $previous) {
                return;
            }

            $eventId = $order->event_id ?? $previous->event_id;

            // Released before the claim, because `event_id` is unique: the
            // successor cannot take the event while the old row still holds it.
            // Safe to hand back to no one — scopeUnconsumed() reads the successor
            // to know this order is spent, not its own columns.
            if ($previous->event_id !== null) {
                $previous->update(['event_id' => null, 'consumed_at' => null]);
            }

            if ($eventId !== null) {
                $order->update(['event_id' => $eventId, 'consumed_at' => $order->consumed_at ?? Carbon::now()]);
                Event::whereKey($eventId)->update(['plan_id' => $order->plan_id]);
            }
        });
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
