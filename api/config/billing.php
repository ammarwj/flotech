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

    'issuer_email' => env('BILLING_ISSUER_EMAIL', 'billing@floevent.id'),

    'issuer_npwp' => env('BILLING_ISSUER_NPWP'),

    // Numbers are formatted <prefix>/<year>/<month>/<seq>, sequence resets monthly.
    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),

    'receipt_prefix' => env('BILLING_RECEIPT_PREFIX', 'KW'),

    /*
    |--------------------------------------------------------------------------
    | Participant documents
    |--------------------------------------------------------------------------
    |
    | Tickets and registration fees are issued by the ORGANIZER, not by us —
    | that money lands in their wallet, so the identity on those PDFs comes
    | from the `organizations` row, not from the issuer fields above.
    |
    | Separate prefixes so each stream keeps its own monthly sequence. Sharing
    | one prefix would interleave three unrelated ledgers into a single run of
    | numbers, and a gap in an invoice sequence is exactly what an auditor asks
    | about.
    |
    */

    'ticket_invoice_prefix' => env('BILLING_TICKET_INVOICE_PREFIX', 'INV-T'),

    'ticket_receipt_prefix' => env('BILLING_TICKET_RECEIPT_PREFIX', 'KW-T'),

    'registration_invoice_prefix' => env('BILLING_REG_INVOICE_PREFIX', 'INV-R'),

    'registration_receipt_prefix' => env('BILLING_REG_RECEIPT_PREFIX', 'KW-R'),

    // Days a past_due invoice stays payable before it reads as overdue.
    'due_days' => (int) env('BILLING_DUE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Idle credits
    |--------------------------------------------------------------------------
    |
    | A paid plan nobody has spent on an event is money taken for nothing yet.
    | It never expires — taking it back would be taking money — so the only
    | thing to do is remind its owner it is there.
    |
    | `idle_credit_days` is how long a credit sits before the first nudge;
    | `idle_credit_repeat_days` is the gap before another one, so a credit left
    | for a year does not send 365 emails. Zero disables the reminder entirely.
    |
    */

    'idle_credit_days' => (int) env('BILLING_IDLE_CREDIT_DAYS', 14),

    'idle_credit_repeat_days' => (int) env('BILLING_IDLE_CREDIT_REPEAT_DAYS', 30),

];
