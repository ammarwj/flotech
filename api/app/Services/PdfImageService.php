<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Turning a stored upload into something dompdf can actually draw.
 *
 * Extracted from TeamAlbumService when the match report became the second sheet
 * that needed it. The reasoning below is the part worth having in one place: it
 * is not a helper, it is a list of things dompdf silently does not do, and a
 * second copy of it would be a second copy that stops being true.
 */
class PdfImageService
{
    public function __construct(protected R2StorageService $r2) {}

    /**
     * A stored image inlined as a small JPEG data-URI, or null when there is
     * none / it can't be fetched. Re-encoded (not passed through) for two
     * reasons, both copied from CertificateService::backgroundDataUri(): our
     * uploads land as WebP and dompdf cannot draw WebP, and inlining spares
     * dompdf a remote fetch per image.
     *
     * Both shapes normalize the aspect ratio here rather than in CSS, because
     * dompdf honours neither `object-fit` nor `max-height`: whatever ratio
     * arrives is what gets stretched into the box in the stylesheet.
     *
     *  - `photo` crops to the 3x4 a photo box is drawn at, so a square-ish
     *    selfie is trimmed instead of squashed.
     *  - `logo` is padded onto a square canvas instead, since cropping a crest
     *    would cut it. The padding is white, which is also what a transparent
     *    PNG flattens to on the way into a JPEG, so it is invisible on the
     *    page — and it is what lets a masthead keep a fixed height no matter
     *    how tall or wide the uploaded logo is.
     */
    public function dataUri(?string $url, string $shape = 'logo'): ?string
    {
        if (! $url) {
            return null;
        }

        $bytes = $this->fetchBytes($url);

        if ($bytes === null) {
            return null;
        }

        try {
            $image = (new ImageManager(new GdDriver))->decodeBinary($bytes);

            $image = $shape === 'photo'
                ? $image->cover(300, 400)
                : $image->contain(400, 400, background: 'ffffff');

            $jpeg = (string) $image->encode(new JpegEncoder(quality: 82));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    /** Copied from CertificateService::fetchBytes() — see that docblock. */
    protected function fetchBytes(string $url): ?string
    {
        $publicBase = rtrim((string) config('r2.public_url'), '/');

        try {
            if ($publicBase !== '' && str_starts_with($url, $publicBase.'/')) {
                return $this->r2->disk()->get(ltrim(Str::after($url, $publicBase), '/'));
            }

            $response = Http::timeout(15)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
