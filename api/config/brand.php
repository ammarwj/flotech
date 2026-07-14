<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brand identity (email)
    |--------------------------------------------------------------------------
    |
    | How the product presents itself in the inbox. Deployed like config/wallet.php
    | rather than editable from the admin UI, so the mail templates read from here
    | instead of hardcoding a name in Blade.
    |
    | Kept apart from config/billing.php on purpose: that one is the *legal issuer*
    | of an invoice (address, NPWP), which is a different thing from the product
    | signing off an email.
    |
    */

    'name' => env('BRAND_NAME', env('APP_NAME', 'flo-event')),

    'tagline' => env('BRAND_TAGLINE', 'Manajemen event olahraga'),

    // Where a recipient replies when something looks wrong.
    'support_email' => env('BRAND_SUPPORT_EMAIL', 'halo@flo-event.id'),

    // The dashboard, not the API. Header wordmark links here.
    'url' => env('BRAND_URL', env('FRONTEND_URL', 'http://localhost:3000')),

];
