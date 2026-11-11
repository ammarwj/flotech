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

    // Twin of config('id_card.max_recipients'). The cap is what makes a bulk
    // zip bounded: GenerateCertificateZipJob pulls one object out of the bucket
    // per row, and an organization with years of events behind it would
    // otherwise ask for every certificate it ever issued in one request.
    'max_batch' => 500,

    /*
     * Fields a template may place on the background, and where their values come
     * from. The editor reads this list through GET /certificate-fields, so adding
     * one here is all it takes for organizers to be able to place it.
     *
     * This is the FIXED half of that list. The other half is whatever the
     * organizer asked on the event's registration form, joined on at request
     * time by App\Support\TemplateFields — a key here and a `custom:` key from
     * there are placed, validated and rendered through exactly the same code,
     * which is what stops the two halves drifting apart.
     *
     * The three player columns at the bottom are blank on a team certificate,
     * and a blank field draws nothing — the same as an unanswered custom one.
     * `position` prints the sport's own label ("Kiper"), never the stored slug.
     */
    'fields' => [
        'recipient_name' => 'Nama penerima',
        'team_name' => 'Nama tim',
        'award_title' => 'Penghargaan',
        'event_name' => 'Nama event',
        'event_date' => 'Tanggal event',
        'organization_name' => 'Penyelenggara',
        'certificate_number' => 'Nomor sertifikat',
        'qr' => 'QR verifikasi',

        'jersey_number' => 'Nomor punggung',
        'position' => 'Posisi',
        'date_of_birth' => 'Tanggal lahir',
    ],
];
