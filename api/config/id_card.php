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
     * `qr` is deliberately absent, unlike the certificate catalogue. A
     * certificate's QR points at /verify/{number}, a page that really exists,
     * because a certificate row carries that number. An ID card has no row, no
     * number, and nothing pointing at it — ScanController scans *tickets*. A QR
     * that 404s in a marshal's hand at the gate is worse than no QR at all.
     *
     * Two coordinate conventions live in this catalogue, and this is the single
     * easiest thing to misread in the renderer:
     *
     *   text fields — `x`/`y` are the point the text is aligned *to*, exactly
     *     as in the certificate editor, so the two read alike.
     *   the photo field — `x`/`y` are the TOP-LEFT CORNER of its box, because a
     *     box that is resized from a corner has to be anchored at one.
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
     */
    'fields' => [
        'photo' => 'Foto',
        'name' => 'Nama',
        'role_label' => 'Sebagai',
        'team_name' => 'Nama tim',
        'event_name' => 'Nama event',
    ],
];
