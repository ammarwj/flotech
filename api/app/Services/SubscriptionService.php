<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the lifecycle of a plan subscription: checkout, retrying an unpaid
 * invoice, and applying a paid one to its organization.
 *
 * Unlike ticket and registration payments, subscription money is the
 * platform's revenue — it never touches an organizer wallet.
 *
 * Numbering: a subscription gets its invoice number the moment it is created
 * (an unpaid invoice is still a document you can hand to finance) and its
 * receipt number only once it is actually paid.
 */
class SubscriptionService
{
    public function __construct(protected MidtransService $midtrans) {}

    /**
     * Create a pending subscription and its Snap transaction. Without Midtrans
     * credentials the subscription is activated immediately (dev convenience).
     *
     * @return array{subscription: Subscription, snap_token: string|null, redirect_url: string|null, mock: bool}
     */
    public function checkout(Organization $org, Plan $plan, string $cycle): array
    {
        $amount = $cycle === 'yearly' ? (float) $plan->price_yearly : (float) $plan->price_monthly;
        $startsAt = Carbon::now();
        $expiresAt = $cycle === 'yearly' ? $startsAt->copy()->addYear() : $startsAt->copy()->addMonth();

        $subscription = $org->subscriptions()->create([
            'plan_id' => $plan->id,
            'invoice_number' => $this->nextNumber('invoice'),
            'billing_cycle' => $cycle,
            'amount' => $amount,
            'status' => 'past_due', // awaiting payment; flips to active on settlement
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
        ]);

        return $this->openSnap($subscription);
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
     * @return array{subscription: Subscription, snap_token: string|null, redirect_url: string|null, mock: bool}
     */
    public function pay(Subscription $subscription): array
    {
        return $this->openSnap($subscription);
    }

    /**
     * @return array{subscription: Subscription, snap_token: string|null, redirect_url: string|null, mock: bool}
     */
    protected function openSnap(Subscription $subscription): array
    {
        $org = $subscription->organization;
        $orderId = 'SUB-'.Str::upper(Str::random(10));

        $snap = $this->midtrans->createSnapTransaction(
            ['order_id' => $orderId, 'gross_amount' => (int) round((float) $subscription->amount)],
            ['first_name' => $org->name, 'email' => $org->contact_email],
            rtrim((string) config('app.frontend_url'), '/').'/organizer/subscription?status=success',
        );

        $subscription->update([
            'midtrans_order_id' => $orderId,
            'midtrans_token' => $snap['token'],
        ]);

        if ($snap['mock']) {
            // No payment gateway configured — activate immediately for dev.
            $this->activate($subscription);
        }

        return [
            'subscription' => $subscription->load('plan'),
            'snap_token' => $snap['token'],
            'redirect_url' => $snap['redirect_url'],
            'mock' => $snap['mock'],
        ];
    }

    /**
     * Apply a paid subscription to its organization.
     *
     * Midtrans re-delivers webhooks, so this must be safe to run twice: an
     * already-issued receipt number is never reissued.
     */
    public function activate(Subscription $subscription, ?string $paymentType = null): void
    {
        $subscription->update([
            'status' => 'active',
            'paid_at' => $subscription->paid_at ?? Carbon::now(),
            'receipt_number' => $subscription->receipt_number ?? $this->nextNumber('receipt'),
            'payment_type' => $paymentType ?? $subscription->payment_type,
        ]);

        $subscription->organization->update([
            'plan_id' => $subscription->plan_id,
            'plan_expires_at' => $subscription->expires_at,
        ]);
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
            $last = Subscription::where($column, 'like', "{$prefix}/{$period}/%")
                ->orderByDesc($column)
                ->lockForUpdate()
                ->value($column);

            $seq = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

            return sprintf('%s/%s/%04d', $prefix, $period, $seq);
        });
    }
}
