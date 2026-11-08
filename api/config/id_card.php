<?php

/*
|--------------------------------------------------------------------------
| ID card rules
|--------------------------------------------------------------------------
| Same idea as config/certificate.php: the numbers the renderer works from live
| here, never hardcoded in a service or a component.
*/

return [
    // 85,6mm at 300 DPI is 1011px wide. At 150 the text on a lanyard card goes
    // soft at arm's length, which is the only distance anyone reads one from.
    'dpi' => (int) env('ID_CARD_DPI', 300),

    // Mirrors GenerateCertificatesRequest. The cap is what makes the batch
    // bounded; see GenerateIdCardsJob for why every batch is queued regardless.
    'max_recipients' => 500,

    // Card numbers: IDC-2026-11-0001, restarting each month. Dashes, not the
    // slashes used for invoices — this number goes in a URL and a filename.
    // Same scheme as config('certificate.number_prefix').
    'number_prefix' => env('ID_CARD_PREFIX', 'IDC'),

    // Where the QR on the card points. The web app serves /verify-card/{number},
    // a separate route from the certificate's /verify/{number}: the two prove
    // different documents and say different things, and one page branching on
    // which kind of number it was handed would be a second reader of that rule.
    'verify_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/').'/verify-card',

    /*
     * GD draws nothing without a real TTF on disk and cannot synthesise bold,
     * so both weights are committed to the repo rather than resolved from the
     * system. Deliberately NOT read out of vendor/dompdf/dompdf/lib/fonts: that
     * directory is a transitive asset of the PDF dependency and `vendor/` is
     * gitignored, so one composer update tidying it would kill every ID card
     * with no other test failing.
     */
    'fonts' => [
        'regular' => resource_path('fonts/DejaVuSans.ttf'),
        'bold' => resource_path('fonts/DejaVuSans-Bold.ttf'),
    ],

    /*
     * Fields a template may place on the background. The editor reads this list
     * through GET /id-card-fields, so adding one here is all it takes for
     * organizers to be able to place it.
     *
     * `qr` was deliberately absent until id_cards existed, and the reason is
     * worth keeping because it is also the condition that had to be met: a QR
     * needs a number to encode and a page that answers it, and a card used to
     * have neither — ScanController scans *tickets*. A QR that 404s in a
     * marshal's hand at the gate is worse than no QR at all. The id_cards table
     * supplies both halves, so the field is here now; the one thing that must
     * stay true is that a template may only place `qr` on cards that are issued
     * as rows, which is every card, since IdCardService issues one per print.
     *
     * Two coordinate conventions live in this catalogue, and this is the single
     * easiest thing to misread in the renderer:
     *
     *   text fields — `x`/`y` are the point the text is aligned *to*, exactly
     *     as in the certificate editor, so the two read alike.
     *   boxes (`photo`, `qr`) — `x`/`y` are the TOP-LEFT CORNER, because a box
     *     that is resized from a corner has to be anchored at one.
     *
     * Text field shape:
     *   {"key":"name","x":50,"y":62,"size":4.5,"color":"#111827",
     *    "align":"center","bold":true,"uppercase":false,"wrap":90}
     *   `size` is MILLIMETRES, not points and not a percentage: the card itself
     *   is in mm, so "the name is 5mm tall" survives swapping CR80 for A6 and
     *   can be checked with a ruler. `wrap` is a percentage of card width.
     *
     * Photo field shape (at most one per template):
     *   {"key":"photo","x":30,"y":8,"w":40,"h":46,"fit":"cover","radius":12}
     *   `w`/`h` are percentages of card width/height, `radius` 0-50 is a
     *   percentage of the box's SHORTER side (0 = square, 50 = circle).
     *
     * QR field shape:
     *   {"key":"qr","x":72,"y":60,"w":22,"h":22}
     *   A box like the photo, and NOT a text field: a QR is a square drawn into
     *   an area, not a string hanging off an alignment point. It takes neither
     *   `fit` nor `radius`, and both are refused rather than ignored —
     *   `contain` is meaningless on something already square, and rounding the
     *   corners eats the finder patterns a scanner locates the code by. The
     *   renderer squares the box off its shorter side, so w != h just leaves
     *   slack rather than stretching the code into something unreadable.
     */
    'fields' => [
        'photo' => 'Foto',
        'name' => 'Nama',
        'role_label' => 'Sebagai',
        'team_name' => 'Nama tim',
        'event_name' => 'Nama event',
        'qr' => 'QR verifikasi',
    ],
];
