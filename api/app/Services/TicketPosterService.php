<?php

namespace App\Services;

use App\Models\Event;
use App\Support\QrImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Carbon;

/**
 * The QR poster an organizer prints and tapes to a locker at the venue.
 *
 * A PDF rather than an on-screen QR the browser prints, for the reason the
 * feature was asked for: *"nanti bisa di cetak dan di tempel di loker"*. The
 * artefact is a sheet of paper on a wall, so it is laid out as one — A4, the
 * code big enough to scan from a queue, and the URL spelled out underneath for
 * whoever is standing too far back to scan it. Same shape as every other
 * "Cetak" here (album, lineup sheet, certificate): dompdf, synchronous, the
 * builder handed back so the controller names the file.
 *
 * The URL is the *platform* one — `{app}/{org}/{event}/tickets` — even for an
 * event that has its own hostname. Print outlives configuration: a poster is
 * taped up once and stays there, while a custom domain can be retired or let
 * lapse, and `proxy.ts` already 301s the platform path onto a live domain in
 * both directions. The reverse is not true, which is what settles it.
 */
class TicketPosterService
{
    public function __construct(protected PdfImageService $images) {}

    /** Where a buyer who scans the code lands. */
    public function purchaseUrl(Event $event): string
    {
        $event->loadMissing('organization');

        return rtrim((string) config('app.frontend_url'), '/')
            .'/'.$event->organization->slug
            .'/'.$event->slug
            .'/tickets';
    }

    public function build(Event $event): DomPdf
    {
        return Pdf::loadHTML($this->html($event))->setPaper('a4', 'portrait');
    }

    /**
     * The sheet before dompdf turns it into a page.
     *
     * Split out of build() for the same reason TeamAlbumService does it: dompdf
     * output is compressed streams, so a test holding the bytes can only say
     * that *something* rendered — and that assertion stays green when the QR
     * stops carrying the right URL, which is the one thing this sheet is for.
     */
    public function html(Event $event): string
    {
        $event->loadMissing('organization');
        $url = $this->purchaseUrl($event);

        return view('pdf.ticket-poster', [
            'event' => $event,
            'organizer' => $event->organization?->name,
            'logo' => $this->images->dataUri($event->organization?->logo_url),
            'qr' => QrImage::svgDataUri($url, 600),
            'url' => $url,
            'sportLabel' => Catalog::sport($event->sport_type)['name'] ?? null,
            'venue' => $event->location_name,
            // Formatted here, not in the view: the sheet has no helpers of its
            // own, same rule the other PDFs follow. The event's own zone, so a
            // poster printed for a WITA tournament is not stamped in UTC.
            'printedAt' => 'Dicetak '.Carbon::now($event->timezone ?: config('app.timezone'))
                ->locale('id')->translatedFormat('d F Y, H:i'),
        ])->render();
    }
}
