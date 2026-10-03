<?php

namespace Tests\Feature;

use App\Services\PdfImageService;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Tests\TestCase;

/**
 * How an upload is trimmed on its way into a printed sheet.
 *
 * The case that matters is a phone portrait: taller than the 3x4 box, so the
 * crop has to drop something. A centred crop drops the top — which is the
 * subject's head, and these sheets exist so panitia can recognise faces.
 */
class PdfPhotoCropTest extends TestCase
{
    /** A PNG whose top third is red and whose bottom two thirds are blue. */
    private function bandedPng(int $w, int $h): string
    {
        $image = (new ImageManager(new GdDriver))->createImage($w, $h)->fill('0000ff');

        $image->drawRectangle(function (RectangleFactory $r) use ($w, $h) {
            $r->at(0, 0);
            $r->size($w, (int) round($h / 3));
            $r->background('ff0000');
        });

        return (string) $image->encode(new PngEncoder);
    }

    private function decodeUri(string $uri): ImageInterface
    {
        $this->assertStringStartsWith('data:image/jpeg;base64,', $uri);

        return (new ImageManager(new GdDriver))
            ->decodeBinary(base64_decode(substr($uri, strlen('data:image/jpeg;base64,'))));
    }

    /** JPEG at quality 82 shifts the exact value, so compare the dominant channel. */
    private function isRed(ImageInterface $image, int $x, int $y): bool
    {
        $c = $image->colorAt($x, $y)->channels();

        return $c[0]->value() > 160 && $c[2]->value() < 90;
    }

    public function test_a_tall_photo_keeps_its_top_and_loses_its_bottom(): void
    {
        Http::fake(['*' => Http::response($this->bandedPng(300, 600), 200)]);

        $photo = $this->decodeUri(app(PdfImageService::class)->dataUri('https://cdn.test/a.png', 'photo'));

        $this->assertSame([300, 400], [$photo->width(), $photo->height()]);

        // 300x600 covering a 300x400 box drops 200 rows. Anchored to the top
        // they all come off the bottom, so the red band still runs to y=199.
        // A centred crop would start 100 rows in and red would end at y=99 —
        // this pair is the whole test; "the top pixel is red" stays green
        // under either anchor, because red starts at the very top either way.
        $this->assertTrue($this->isRed($photo, 150, 10), 'top of the frame should be kept');
        $this->assertTrue($this->isRed($photo, 150, 180), 'a centred crop cut 100 rows off the head');
        $this->assertFalse($this->isRed($photo, 150, 260), 'the band should still end inside the frame');
    }

    public function test_a_logo_is_padded_square_instead_of_cropped(): void
    {
        Http::fake(['*' => Http::response($this->bandedPng(300, 600), 200)]);

        $logo = $this->decodeUri(app(PdfImageService::class)->dataUri('https://cdn.test/a.png'));

        // The other shape, in the same file: a crest loses nothing, so the
        // red band is squeezed to a third of 400 rather than kept whole, and
        // the sides are white padding. Asserting only the photo shape would
        // stay green if someone gave logos the same top-anchored crop.
        $this->assertSame([400, 400], [$logo->width(), $logo->height()]);
        $this->assertTrue($this->isRed($logo, 200, 20));
        $this->assertFalse($this->isRed($logo, 200, 200));
        $this->assertSame('#ffffff', $logo->colorAt(10, 200)->toHex(true));
    }
}
