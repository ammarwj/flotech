<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR code as an inline SVG data-URI, which is the only shape our PDFs want.
 *
 * SVG rather than a raster: dompdf draws it through php-svg-lib, so the code
 * stays crisp at whatever size the sheet puts it at — and a printed QR is read
 * by a phone camera at arm's length, where a resampled PNG is exactly what
 * fails to scan.
 *
 * Its own class because there are two callers now (certificate verification and
 * the ticket poster) and the next one will be a third. A second copy of the
 * writer would be a second copy of the margin, which is not cosmetic: a QR
 * printed hard against its neighbours does not scan.
 */
class QrImage
{
    /**
     * @param  int  $size  Pixel box the SVG declares; the sheet still scales it.
     */
    public static function svgDataUri(string $url, int $size = 300): string
    {
        // Margin 0: every sheet that uses this draws its own quiet zone as
        // padding, so the renderer's would only make the code smaller inside
        // the box it was given.
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url));
    }
}
