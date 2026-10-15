<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment rails
    |--------------------------------------------------------------------------
    |
    | Buyers normally pay the platform's Midtrans account and the organizer's
    | share lands in their wallet (see config/wallet.php). When Midtrans is
    | unavailable, a super admin turns the gateway off and every organization
    | falls back to manual bank transfer: the buyer transfers straight to the
    | organizer's own account, uploads proof, and an org admin approves it.
    |
    | Manual transfer is a fallback, never a choice an organization makes — it
    | earns the platform nothing (we never hold the money, so there is nothing
    | to take a fee from), so letting organizers opt in would simply end fee
    | revenue. These are defaults; `payment_gateway_enabled` is overridable at
    | runtime from /admin/settings (see App\Services\PlatformSettings).
    |
    */

    'gateway_enabled' => (bool) env('PAYMENTS_GATEWAY_ENABLED', true),

    // How long a manual order may sit unpaid before it is cancelled and its
    // ticket quota released. Nothing expires an order once proof is uploaded —
    // that is the organizer's call.
    'manual_order_ttl_hours' => (int) env('PAYMENTS_MANUAL_ORDER_TTL_HOURS', 24),

    // The platform's own margin, on top of the gateway's own fee, charged to
    // whoever is paying. Flat rupiah, not a percentage — split four ways, and
    // the split follows two different seams. The first is who pays: a
    // participant paying an organizer is not an organizer paying us. The second
    // is what is being bought: a ticket is sold per seat and priced in tens of
    // thousands, a team registration is sold once per team at ten times that,
    // a race entry once per runner somewhere between them — so one rate that
    // suits any of them is wrong for the others. Any of the four may sit at 0
    // without dragging the others. See config/payment_fees.php for the gateway
    // fee itself.
    'ticket_service_fee_amount' => (float) env('PAYMENTS_TICKET_SERVICE_FEE_AMOUNT', 0),
    'registration_service_fee_amount' => (float) env('PAYMENTS_REGISTRATION_SERVICE_FEE_AMOUNT', 0),
    'plan_service_fee_amount' => (float) env('PAYMENTS_PLAN_SERVICE_FEE_AMOUNT', 0),
    // A fourth, for a fun run entry. One runner, one place — nothing
    // multiplies it, unlike a ticket basket. See
    // PaymentFeeCalculator::AUDIENCE_RACE_ENTRY for why it is not one of the
    // three above reused.
    'race_entry_service_fee_amount' => (float) env('PAYMENTS_RACE_ENTRY_SERVICE_FEE_AMOUNT', 0),

];
