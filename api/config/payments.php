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

];
