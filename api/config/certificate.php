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

    /*
     * The TTFs CertificateService measures text against before handing it to
     * dompdf, so a long name can be shrunk to fit its box.
     *
     * The same two files config('id_card.fonts') names, and that is the point
     * rather than a shortcut: `pdf/certificate.blade.php` sets
     * `font-family: DejaVu Sans`, which dompdf resolves to its own bundled copy
     * of this very face — so measuring against these files measures the metrics
     * the PDF will actually be laid out with.
     *
     * Read from resource_path() and NOT from
     * vendor/dompdf/dompdf/lib/fonts: that directory is a transitive asset of
     * the PDF dependency and `vendor/` is gitignored, so one composer update
     * tidying it would silently take the fitting with it — the same reasoning
     * written out at config('id_card.fonts').
     *
     * Spelled here rather than read off the ID card config, because a service
     * reaching into another surface's settings is how "change the card font"
     * turns into "certificates are measured wrong".
     */
    'fonts' => [
        'regular' => resource_path('fonts/DejaVuSans.ttf'),
        'bold' => resource_path('fonts/DejaVuSans-Bold.ttf'),
    ],

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
     * `category_name` is NOT one of those three: the category belongs to the
     * team, so it prints on a player's certificate and on their squad's alike.
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

        'category_name' => 'Kategori',
        'jersey_number' => 'Nomor punggung',
        'position' => 'Posisi',
        'date_of_birth' => 'Tanggal lahir',
    ],
];
