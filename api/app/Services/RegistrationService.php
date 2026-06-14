<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Registration-fee payment lifecycle for a team. Mirrors TicketService:
 * a team's `payment_status` flips to 'paid' on Midtrans settlement (or
 * immediately for free events / when no gateway is configured).
 */
class RegistrationService
{
    public function __construct(protected PlanGate $gate, protected MidtransService $midtrans) {}

    /**
     * Platform fee for a registration amount, based on the organizer plan's
     * `registration_fee_percent` feature (0 when unset).
     */
    public function platformFee(Organization $org, float $amount): float
    {
        $percent = (float) ($this->gate->value($org, 'registration_fee_percent') ?? 0);

        return round($amount * $percent / 100, 2);
    }

    /**
     * Start a Midtrans Snap payment for a team's registration fee. Free
     * registrations (or a missing gateway) settle immediately.
     *
     * @return array{snap_token: string|null, redirect_url: string|null, mock: bool}
     */
    public function startPayment(Team $team, Organization $org): array
    {
        $amount = (float) $team->event->registration_fee;

        if ($amount <= 0) {
            $this->markPaid($team);

            return ['snap_token' => null, 'redirect_url' => null, 'mock' => true];
        }

        $orderId = $team->midtrans_order_id ?: 'REG-'.Str::upper(Str::random(10));

        $team->update([
            'payment_status' => 'unpaid',
            'payment_amount' => $amount,
            'platform_fee' => $this->platformFee($org, $amount),
            'midtrans_order_id' => $orderId,
        ]);

        $snap = $this->midtrans->createSnapTransaction(
            ['order_id' => $orderId, 'gross_amount' => (int) round($amount)],
            ['first_name' => $team->contact_name ?? $team->name, 'email' => $org->contact_email],
        );

        if ($snap['token']) {
            $team->update(['midtrans_token' => $snap['token']]);
        }

        if ($snap['mock']) {
            // No payment gateway configured — settle immediately for dev.
            $this->markPaid($team);
        }

        return [
            'snap_token' => $snap['token'],
            'redirect_url' => $snap['redirect_url'],
            'mock' => $snap['mock'],
        ];
    }

    /**
     * Settle a paid registration. Idempotent — re-delivered webhooks are no-ops.
     */
    public function markPaid(Team $team): void
    {
        if ($team->payment_status === 'paid') {
            return;
        }

        $team->update(['payment_status' => 'paid', 'paid_at' => Carbon::now()]);
    }
}
