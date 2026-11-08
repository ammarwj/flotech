<?php

/*
|--------------------------------------------------------------------------
| e-BIB rules
|--------------------------------------------------------------------------
| Same idea as config/id_card.php and config/certificate.php: the numbers the
| renderer works from live here, never hardcoded in a service or a component.
|
| Spelled out separately rather than borrowing from config('id_card.*'), even
| where a value happens to match today. One config is one rulebook: a bib is
| printed on paper at a size nobody wears on a lanyard, and the DPI that reads
| well at arm's length is not the one that reads well across a finish line.
| Sharing the keys would make tuning one of them silently retune the other.
*/

return [
    // A5 at 300 DPI is 2480 x 1748. Same figure as a card, and for the same
    // reason: anything lower and the number softens at the distance it exists
    // to be read from.
    'dpi' => (int) env('BIB_DPI', 300),

    // Mirrors config('id_card.max_recipients'). The cap is what makes the batch
    // bounded; see GenerateBibsJob for why every batch is queued regardless.
    'max_recipients' => 500,

    /*
     * There is deliberately no `number_prefix` and no `verify_url` here, and
     * both absences are decisions rather than omissions:
     *
     *   No number prefix. A bib's number is `race_entries.bib_number`, which
     *     BibService assigns per category ("5K-001"). A document number of this
     *     service's own would be a second number on the same piece of paper,
     *     and the one the organizer reads off a start list is the stored one.
     *
     *   No verification URL. The QR encodes `race_entries.qr_code` — the
     *     check-in credential RaceScanController already scans — so an e-BIB
     *     *is* the race-pack ticket and a runner carries one piece of paper. A
     *     /verify-bib page would hand them two QRs that look identical and
     *     oblige a marshal to know which is which: a second reader of one
     *     document. The binding consequence is that only a PAID entry gets a
     *     QR, because markPaid() is what mints `qr_code` — a credential issued
     *     before the money arrives admits somebody who has not paid.
     */

    /*
     * GD draws nothing without a real TTF on disk and cannot synthesise bold,
     * so both weights come from the repo. Same files the card renderer uses —
     * these are the only two committed, and a bib has no typographic need a
     * card does not. Resolved through resource_path() here rather than read out
     * of config('id_card.fonts') so that retiring the card feature cannot take
     * this one's fonts with it.
     */
    'fonts' => [
        'regular' => resource_path('fonts/DejaVuSans.ttf'),
        'bold' => resource_path('fonts/DejaVuSans-Bold.ttf'),
    ],

    /*
     * Fields a template may place on the background. The editor reads this list
     * through GET /bib-fields, so adding one here is all it takes for
     * organizers to be able to place it.
     *
     * Notably SHORTER than the card catalogue, and each absence is deliberate:
     * no `photo` (a bib is pinned to a chest, and nobody is identified from
     * one), no `role_label` and no `team_name` (a runner holds neither).
     *
     * Two coordinate conventions live in this catalogue, and this is the single
     * easiest thing to misread in the renderer — the same pair documented in
     * config/id_card.php:
     *
     *   text fields — `x`/`y` are the point the text is aligned *to*.
     *   the box (`qr`) — `x`/`y` are the TOP-LEFT CORNER, because a box that is
     *     resized from a corner has to be anchored at one.
     *
     * Text field shape:
     *   {"key":"bib_number","x":50,"y":30,"size":60,"color":"#111827",
     *    "align":"center","bold":true,"uppercase":false,"wrap":90}
     *   `size` is MILLIMETRES, not points and not a percentage: the bib itself
     *   is in mm, so "the number is 60mm tall" survives swapping A5 for A6 and
     *   can be checked with a ruler. `wrap` is a percentage of bib width.
     *
     * QR field shape:
     *   {"key":"qr","x":78,"y":62,"w":18,"h":18}
     *   A box, and NOT a text field: a QR is a square drawn into an area, not a
     *   string hanging off an alignment point. It takes neither `fit` nor
     *   `radius` — there is no `photo` here for those to belong to, and both
     *   are refused rather than ignored: `contain` is meaningless on something
     *   already square, and rounding the corners eats the finder patterns a
     *   scanner locates the code by. The renderer squares the box off its
     *   shorter side, so w != h leaves slack rather than stretching the code
     *   into something unreadable.
     */
    'fields' => [
        'bib_number' => 'Nomor BIB',
        'name' => 'Nama pelari',
        'category_name' => 'Kategori',
        'event_name' => 'Nama event',
        'qr' => 'QR check-in',
    ],
];
