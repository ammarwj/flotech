<?php

/*
|--------------------------------------------------------------------------
| Certificate rules
|--------------------------------------------------------------------------
| Same idea as config/billing.php and config/wallet.php: the numbering and the
| public verification URL live here, never hardcoded in a template or a view.
*/

return [
    // Certificate numbers: CERT-2026-07-0001, restarting each month. Dashes, not
    // the slashes used for invoices — this number goes in a URL and a filename.
    'number_prefix' => env('CERTIFICATE_PREFIX', 'CERT'),

    // Where the QR on the PDF points. The web app serves /verify/{number}.
    'verify_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/').'/verify',

    // Fields a template may place on the background, and where their values come
    // from. The editor reads this list through GET /certificate-fields, so adding
    // one here is all it takes for organizers to be able to place it.
    'fields' => [
        'recipient_name' => 'Nama penerima',
        'team_name' => 'Nama tim',
        'award_title' => 'Penghargaan',
        'event_name' => 'Nama event',
        'event_date' => 'Tanggal event',
        'organization_name' => 'Penyelenggara',
        'certificate_number' => 'Nomor sertifikat',
        'qr' => 'QR verifikasi',
    ],
];
