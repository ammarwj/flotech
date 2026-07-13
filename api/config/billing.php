<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing documents (invoice & receipt)
    |--------------------------------------------------------------------------
    |
    | Identity of the party issuing subscription invoices and receipts — the
    | platform itself, never the organizer. Deployed like config/wallet.php
    | rather than editable from the admin UI, so the PDF templates read from
    | here instead of hardcoding a company name.
    |
    */

    'issuer_name' => env('BILLING_ISSUER_NAME', 'flo-event'),

    'issuer_address' => env('BILLING_ISSUER_ADDRESS', 'Jakarta, Indonesia'),

    'issuer_email' => env('BILLING_ISSUER_EMAIL', 'billing@flo-event.id'),

    'issuer_npwp' => env('BILLING_ISSUER_NPWP'),

    // Numbers are formatted <prefix>/<year>/<month>/<seq>, sequence resets monthly.
    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),

    'receipt_prefix' => env('BILLING_RECEIPT_PREFIX', 'KW'),

    // Days a past_due invoice stays payable before it reads as overdue.
    'due_days' => (int) env('BILLING_DUE_DAYS', 7),

];
