<?php

namespace Tests\Feature;

use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\IdCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The renderer itself: GD, one card at a time, no HTTP and no queue.
 *
 * Every case compares two renders. "It didn't throw" is the failure mode this
 * whole file exists to catch — a renderer that quietly skips the photo, or one
 * that hardcodes CR80, passes any single-state assertion.
 */
class IdCardRenderTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // tests/bootstrap.php blanks the R2 credentials, so IdCardService reads
        // and writes through the `public` disk — the same branch `bun run dev`
        // takes. Faking it keeps the background images out of the repo.
        Storage::fake('public');
    }

    private function service(): IdCardService
    {
        return app(IdCardService::class);
    }

    /** A real PNG on the fake disk, returned as the URL a template row stores. */
    private function image(string $key, int $w, int $h, string $colour): string
    {
        $png = (new ImageManager(new GdDriver))->createImage($w, $h)->fill($colour)->encode(new PngEncoder);

        Storage::disk('public')->put($key, (string) $png);

        return Storage::disk('public')->url($key);
    }

    /** The same, but banded: top third red, rest blue, so a crop is visible. */
    private function bandedImage(string $key, int $w, int $h): string
    {
        $png = (new ImageManager(new GdDriver))->createImage($w, $h)->fill('0000ff');

        $png->drawRectangle(function (RectangleFactory $r) use ($w, $h) {
            $r->at(0, 0);
            $r->size($w, (int) round($h / 3));
            $r->background('ff0000');
        });

        Storage::disk('public')->put($key, (string) $png->encode(new PngEncoder));

        return Storage::disk('public')->url($key);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $fields
     */
    private function template(Organization $org, float $w, float $h, ?array $fields = null): IdCardTemplate
    {
        return $org->idCardTemplates()->create([
            'name' => 'Kartu',
            'background_url' => $this->image('id-cards/bg-'.uniqid().'.png', 400, 260, '#dddddd'),
            'width_mm' => $w,
            'height_mm' => $h,
            'fields' => $fields ?? [
                ['key' => 'photo', 'x' => 6, 'y' => 8, 'w' => 28, 'h' => 62, 'fit' => 'cover', 'radius' => 0],
                ['key' => 'name', 'x' => 40, 'y' => 30, 'size' => 5, 'align' => 'left', 'bold' => true],
                ['key' => 'role_label', 'x' => 40, 'y' => 55, 'size' => 3.5, 'align' => 'left'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function person(array $overrides = []): array
    {
        return [
            'type' => 'personnel',
            'id' => (string) Str::uuid(),
            'name' => 'Ahmad Fauzi',
            'role_label' => 'Wasit',
            'team_name' => '',
            'photo_url' => null,
            ...$overrides,
        ];
    }

    private function decode(string $png): ImageInterface
    {
        return (new ImageManager(new GdDriver))->decodeBinary($png);
    }

    public function test_the_png_matches_the_card_size_at_the_configured_dpi(): void
    {
        config(['id_card.dpi' => 300]);

        $org = $this->orgFor(User::factory()->create());

        $cr80 = $this->decode($this->service()->render(
            $this->template($org, 85.6, 54),
            $this->person(),
        ));

        $a6 = $this->decode($this->service()->render(
            $this->template($org, 105, 148),
            $this->person(),
        ));

        // Both sizes, never one: a renderer that hardcoded 1011x638 — or that
        // simply handed back the background untouched — passes the first pair
        // on its own. The second is portrait, so even a transposed conversion
        // shows up here.
        $this->assertSame([1011, 638], [$cr80->width(), $cr80->height()]);
        $this->assertSame([1240, 1748], [$a6->width(), $a6->height()]);
    }

    public function test_a_missing_photo_renders_a_different_tile_than_a_present_one(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $template = $this->template($org, 85.6, 54);

        $withPhoto = $this->service()->render($template, $this->person([
            'photo_url' => $this->image('personnel/foto.png', 300, 400, '#00ff00'),
        ]));

        $without = $this->service()->render($template, $this->person());

        $a = $this->decode($withPhoto);
        $b = $this->decode($without);

        // Same card, same everything but the photo. Equal dimensions rule out
        // "the photo resized the card"; different bytes rule out the renderer
        // silently skipping a field it could not fetch — which is exactly what
        // an "it didn't throw" assertion would have let through.
        $this->assertSame([$a->width(), $a->height()], [$b->width(), $b->height()]);
        $this->assertNotSame($withPhoto, $without);

        // And specifically inside the photo box: the uploaded green must be
        // there, and the placeholder must not be green. Comparing whole files
        // alone would also pass if the difference were a stray pixel elsewhere.
        $this->assertSame('#00ff00', $a->colorAt(120, 200)->toHex(true));
        $this->assertNotSame('#00ff00', $b->colorAt(120, 200)->toHex(true));
    }

    public function test_two_different_names_get_different_placeholder_colours(): void
    {
        $org = $this->orgFor(User::factory()->create());

        // No photo on either, so the tile is the only thing that can differ.
        // "Ahmad Fauzi" hashes to index 2 and "Citra Dewi" to index 0 in the
        // six-colour list IdCardService shares with crestGradient().
        $template = $this->template($org, 85.6, 54, [
            ['key' => 'photo', 'x' => 6, 'y' => 8, 'w' => 28, 'h' => 62, 'fit' => 'cover', 'radius' => 0],
        ]);

        $first = $this->decode($this->service()->render($template, $this->person(['name' => 'Ahmad Fauzi'])));
        $second = $this->decode($this->service()->render($template, $this->person(['name' => 'Citra Dewi'])));
        $again = $this->decode($this->service()->render($template, $this->person(['name' => 'Ahmad Fauzi'])));

        // Top-left of the box plus a margin, so we are reading flat fill rather
        // than an initial glyph.
        $at = fn ($img) => $img->colorAt(70, 100)->toHex(true);

        // The pair that matters: different names differ, and the same name is
        // stable. A renderer that picked a colour at random passes the first
        // assertion every time and the second never.
        $this->assertNotSame($at($first), $at($second));
        $this->assertSame($at($first), $at($again));
    }

    public function test_a_tall_photo_is_cropped_from_the_bottom_so_the_head_survives(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $template = $this->template($org, 85.6, 54, [
            ['key' => 'photo', 'x' => 6, 'y' => 8, 'w' => 28, 'h' => 62, 'fit' => 'cover', 'radius' => 0],
        ]);

        // 300x600 into a 283x396 box: the photo scales to 283x566 and 170 rows
        // have to go. Top-anchored they all come off the bottom, so the red
        // band runs to row 188 of the box; centred it would start 85 rows in
        // and end at 103.
        $card = $this->decode($this->service()->render($template, $this->person([
            'photo_url' => $this->bandedImage('personnel/tall.png', 300, 600),
        ])));

        $isRed = fn (int $x, int $y) => $card->colorAt($x, $y)->toHex(true) === '#ff0000';

        // The pair is the test: red at the very top stays true under either
        // anchor, because the band starts at row 0 either way. Row 150 is what
        // separates them.
        $this->assertTrue($isRed(201, 61), 'top of the frame should be kept');
        $this->assertTrue($isRed(201, 201), 'a centred crop cut 85 rows off the head');
        $this->assertFalse($isRed(201, 301), 'the band should still end inside the box');
    }

    public function test_contain_keeps_the_whole_photo_rather_than_anchoring_it(): void
    {
        $org = $this->orgFor(User::factory()->create());

        // The other branch, so the anchor added to cover() cannot leak into it:
        // `contain` exists to lose nothing, and a top-anchored crop here would
        // be the bug it is there to avoid. Same photo, same box, one differing
        // field — asserting cover() alone would stay green either way.
        $fields = fn (string $fit) => [
            ['key' => 'photo', 'x' => 6, 'y' => 8, 'w' => 28, 'h' => 62, 'fit' => $fit, 'radius' => 0],
        ];

        $url = $this->bandedImage('personnel/tall-2.png', 300, 600);

        $covered = $this->decode($this->service()->render(
            $this->template($org, 85.6, 54, $fields('cover')),
            $this->person(['photo_url' => $url]),
        ));

        $contained = $this->decode($this->service()->render(
            $this->template($org, 85.6, 54, $fields('contain')),
            $this->person(['photo_url' => $url]),
        ));

        // Contained, the whole 600 rows squeeze into 396, so the band ends
        // around row 132 and row 201 is already blue — exactly where the
        // top-anchored crop is still red.
        $this->assertSame('#ff0000', $covered->colorAt(201, 201)->toHex(true));
        $this->assertNotSame('#ff0000', $contained->colorAt(201, 201)->toHex(true));
    }

    /**
     * The rows of a card that hold any ink, as [topmost, bottommost].
     *
     * Read off the rendered PNG rather than trusted from the field values:
     * where a block of text actually lands is the thing under test, and it is
     * decided inside Intervention.
     *
     * @return array{int, int}
     */
    private function inkRows(ImageInterface $img, int $fromX, int $toX): array
    {
        $top = null;
        $bottom = null;

        for ($y = 0; $y < $img->height(); $y++) {
            for ($x = $fromX; $x < $toX; $x += 2) {
                if ($img->colorAt($x, $y)->toHex(true) !== '#dddddd') {
                    $top ??= $y;
                    $bottom = $y;
                    break;
                }
            }
        }

        return [$top ?? 0, $bottom ?? 0];
    }

    public function test_valign_middle_keeps_a_one_line_and_a_wrapped_name_on_the_same_centre(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $template = fn (string $valign) => $this->template($org, 85.6, 54, [
            [
                'key' => 'name', 'x' => 50, 'y' => 50, 'size' => 4,
                'align' => 'center', 'valign' => $valign, 'wrap' => 60,
            ],
        ]);

        // A name that fits one line, and one long enough to wrap. `y` anchors
        // the whole wrapped block, so this pair is what separates the two
        // anchors — a single name renders the same under either.
        $short = 'ADIB';
        $long = 'AHMAD FAUZI RAHMATULLAH ABDURRAHMAN';

        $centreOf = fn (string $valign, string $name) => array_sum($this->inkRows(
            $this->decode($this->service()->render($template($valign), $this->person(['name' => $name]))),
            0,
            1011,
        )) / 2;

        $topShort = $centreOf('top', $short);
        $topLong = $centreOf('top', $long);
        $midShort = $centreOf('middle', $short);
        $midLong = $centreOf('middle', $long);

        // Both anchors in one test, because either assertion alone passes a
        // build that ignores `valign` entirely: under `top` the wrapped name
        // must sink (that is the reported bug), and under `middle` the two must
        // land on the same centre (that is the fix).
        $this->assertGreaterThan(
            $topShort + 10,
            $topLong,
            'anchored at the top, a wrapped name should sink below a one-line one',
        );

        $this->assertEqualsWithDelta(
            $midShort,
            $midLong,
            4,
            'anchored in the middle, both names should share one centre',
        );
    }

    public function test_a_text_field_without_valign_still_renders_anchored_at_the_top(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $fields = fn (?string $valign) => [
            array_filter([
                'key' => 'name', 'x' => 50, 'y' => 50, 'size' => 4,
                'align' => 'center', 'valign' => $valign, 'wrap' => 60,
            ], fn ($v) => $v !== null),
        ];

        $name = 'AHMAD FAUZI RAHMATULLAH ABDURRAHMAN';

        $absent = $this->service()->render($this->template($org, 85.6, 54, $fields(null)), $this->person(['name' => $name]));
        $explicitTop = $this->service()->render($this->template($org, 85.6, 54, $fields('top')), $this->person(['name' => $name]));
        $middle = $this->service()->render($this->template($org, 85.6, 54, $fields('middle')), $this->person(['name' => $name]));

        // Every template written before `valign` existed omits the key, and
        // those cards must come out exactly as they did. The third render is
        // what makes the first assertion mean something: without it, a renderer
        // that ignored `valign` altogether would pass.
        $this->assertSame($explicitTop, $absent);
        $this->assertNotSame($middle, $absent);
    }

    /** A template whose only field is a QR box, so nothing else can differ. */
    private function qrTemplate(Organization $org, float $w = 22, float $h = 22): IdCardTemplate
    {
        return $this->template($org, 85.6, 54, [
            ['key' => 'qr', 'x' => 60, 'y' => 20, 'w' => $w, 'h' => $h],
        ]);
    }

    private function issuedCard(Organization $org, IdCardTemplate $template, string $number): IdCard
    {
        return $org->idCards()->create([
            'event_id' => $this->eventOn($org)->id,
            'id_card_template_id' => $template->id,
            'card_number' => $number,
            'recipient_type' => 'personnel',
            'recipient_id' => (string) Str::uuid(),
            'recipient_name' => 'Ahmad Fauzi',
            'issued_at' => now(),
        ]);
    }

    public function test_a_qr_field_draws_only_when_the_card_row_is_passed(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $template = $this->qrTemplate($org);

        $card = $this->issuedCard($org, $template, 'IDC-2026-11-0001');

        $withQr = $this->service()->render($template, $this->person(), null, $card);
        $without = $this->service()->render($template, $this->person());

        $a = $this->decode($withQr);
        $b = $this->decode($without);

        // The pair is the whole test. render() takes the card as an *optional*
        // fourth argument so existing callers and every QR-less template keep
        // working, and an optional argument that is silently never read looks
        // exactly like one that works — asserting only that the QR render
        // differs from a blank card would stay green if drawQr() drew on every
        // render regardless of whether a row was supplied.
        $this->assertSame([$a->width(), $a->height()], [$b->width(), $b->height()]);
        $this->assertNotSame($withQr, $without);

        // Specifically inside the box: a quiet zone of white and modules of
        // black. The background is #dddddd, so neither colour can come from it.
        $pixels = [];
        for ($x = 720; $x < 940; $x += 4) {
            for ($y = 110; $y < 330; $y += 4) {
                $pixels[$a->colorAt($x, $y)->toHex(true)] = true;
            }
        }

        $this->assertArrayHasKey('#000000', $pixels, 'no QR modules were drawn');
        $this->assertArrayHasKey('#ffffff', $pixels, 'no quiet zone was drawn');
        $this->assertArrayNotHasKey('#000000', (function () use ($b) {
            $seen = [];
            for ($x = 720; $x < 940; $x += 4) {
                for ($y = 110; $y < 330; $y += 4) {
                    $seen[$b->colorAt($x, $y)->toHex(true)] = true;
                }
            }

            return $seen;
        })(), 'the QR was drawn without a card row');
    }

    public function test_two_card_numbers_produce_two_different_codes(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $template = $this->qrTemplate($org);

        $first = $this->service()->render(
            $template,
            $this->person(),
            null,
            $this->issuedCard($org, $template, 'IDC-2026-11-0001'),
        );

        $second = $this->service()->render(
            $template,
            $this->person(),
            null,
            $this->issuedCard($org, $template, 'IDC-2026-11-0002'),
        );

        $sameCard = $org->idCards()->where('card_number', 'IDC-2026-11-0001')->firstOrFail();
        $again = $this->service()->render($template, $this->person(), null, $sameCard);

        // Different numbers encode differently, and the same number is stable.
        // A drawQr() that encoded a constant — the verify base without the
        // number appended, say — passes "modules were drawn" every time and
        // this never.
        $this->assertNotSame($first, $second);
        $this->assertSame($first, $again);
    }

    public function test_the_qr_is_centred_and_its_modules_are_whole_pixels(): void
    {
        $org = $this->orgFor(User::factory()->create());

        // A deliberately awkward box: 22% of 1011px is 222px, which does not
        // divide by the 41 cells (33 modules + 2x4 quiet) a verification URL
        // needs. Float module widths would be drawn alternately 5 and 6 px and
        // walk the finder patterns out of square.
        $template = $this->qrTemplate($org);
        $card = $this->issuedCard($org, $template, 'IDC-2026-11-0001');

        $img = $this->decode($this->service()->render($template, $this->person(), null, $card));

        $boxX = (int) round(0.60 * 1011);
        $boxY = (int) round(0.20 * 638);
        $boxW = (int) round(0.22 * 1011);
        $boxH = (int) round(0.22 * 638);

        // Walk the box's middle row and collect the white plate's extent. The
        // plate is (modules + 8) * scale wide with scale floored, so it is
        // *narrower* than the box — and centring is what keeps the leftover
        // split evenly rather than shoved against one edge.
        $midY = $boxY + intdiv($boxH, 2);
        $whiteXs = [];
        for ($x = $boxX; $x < $boxX + $boxW; $x++) {
            $hex = $img->colorAt($x, $midY)->toHex(true);
            if ($hex === '#ffffff' || $hex === '#000000') {
                $whiteXs[] = $x;
            }
        }

        $this->assertNotEmpty($whiteXs, 'the plate was never drawn');

        // The plate is square (sized off the box's shorter side) and centred in
        // the rectangle, so a wide box leaves slack on both left and right.
        $leftGap = min($whiteXs) - $boxX;
        $rightGap = ($boxX + $boxW) - (max($whiteXs) + 1);

        // Within a pixel of each other: intdiv() cannot split an odd remainder
        // evenly. An uncentred plate pins one gap at 0 and the other at the
        // whole remainder, which is what a missing centring step looks like.
        $this->assertLessThanOrEqual(1, abs($leftGap - $rightGap), 'the QR is not centred in its box');
    }

    public function test_a_qr_box_too_small_for_one_pixel_per_module_draws_nothing(): void
    {
        $org = $this->orgFor(User::factory()->create());

        // 3% of 1011px is 30px for 41 cells — less than one pixel each.
        $tiny = $this->qrTemplate($org, 3, 3);
        $roomy = $this->qrTemplate($org, 22, 22);

        $card = $this->issuedCard($org, $tiny, 'IDC-2026-11-0001');

        $small = $this->service()->render($tiny, $this->person(), null, $card);
        $large = $this->service()->render($roomy, $this->person(), null, $card);

        // The pair matters: a grey smudge that looks like a QR in a thumbnail
        // and scans as nothing is worse than a gap the organizer can see, so
        // the renderer bails — but it must only bail on the small one. A
        // drawQr() that bailed always passes the first assertion alone.
        $this->assertSame(
            '#dddddd',
            $this->decode($small)->colorAt(640, 150)->toHex(true),
            'something was drawn into a box too small to hold a module',
        );
        $this->assertNotSame($small, $large);
    }

    /**
     * A background whose aspect ratio differs from the card's is STRETCHED to
     * fit, never cropped — so a mark the designer put at a third of the artwork
     * prints at a third of the card.
     *
     * This is a bug that happened. The renderer used `cover()` while the editor
     * shows the same artwork with CSS `object-fit: fill`, so on a 1044x1506
     * background over a 104x145mm card the image was scaled up 1.1762x and 29px
     * came off each edge — every plate drifted DOWN relative to the card, and
     * further the lower it sat, while the text went exactly where the organizer
     * had put it. On screen it read as text that would not centre; it was the
     * background that had moved underneath it.
     *
     * The test image's ratio is deliberately nothing like the card's: a square
     * one passes under either rule and proves nothing.
     */
    public function test_a_background_of_a_different_ratio_is_stretched_rather_than_cropped(): void
    {
        $org = $this->orgFor(User::factory()->create());

        // 0.693 artwork on a 1.585 card. bandedImage() paints the top third red
        // and the rest blue.
        $tall = $this->bandedImage('id-cards/tall-bg-'.uniqid().'.png', 1044, 1506);

        $template = $org->idCardTemplates()->create([
            'name' => 'Kartu',
            'background_url' => $tall,
            'width_mm' => 85.6,
            'height_mm' => 54,
            // No text: the background alone is under test.
            'fields' => [['key' => 'photo', 'x' => 90, 'y' => 90, 'w' => 5, 'h' => 5]],
        ]);

        $card = $this->decode($this->service()->render($template, $this->person()));

        // Stretched, the boundary stays at one third of the card's height
        // whatever the two ratios were. Cropped, it moves.
        $third = (int) round($card->height() / 3);

        $this->assertSame(
            '#ff0000',
            $card->colorAt(500, $third - 14)->toHex(true),
            'the artwork’s top third must still be the card’s top third',
        );

        $this->assertSame(
            '#0000ff',
            $card->colorAt(500, $third + 14)->toHex(true),
            'and the boundary must not have been pushed by a crop',
        );
    }

    /**
     * A name too long for its box comes out smaller, and one that fits does
     * not.
     *
     * Both halves in one test, because each alone passes a broken build: a
     * renderer that shrank unconditionally satisfies "the long name got
     * smaller", and one that never shrinks satisfies "the short name did not".
     * The height is read off the PNG rather than from the field, because what
     * is under test is a decision made inside the renderer.
     */
    public function test_a_long_value_is_shrunk_to_fit_its_box_while_a_short_one_is_not(): void
    {
        $org = $this->orgFor(User::factory()->create());

        // 14% of 54mm ≈ 7.6mm — room for one 5mm line, not two.
        $boxed = $this->template($org, 85.6, 54, [
            [
                'key' => 'name', 'x' => 50, 'y' => 50, 'size' => 5,
                'align' => 'center', 'valign' => 'middle', 'w' => 60, 'h' => 14,
            ],
        ]);

        $heightOf = fn (IdCardTemplate $template, string $name) => (function (array $rows) {
            return $rows[1] - $rows[0];
        })($this->inkRows(
            $this->decode($this->service()->render($template, $this->person(['name' => $name]))),
            0,
            1011,
        ));

        $short = 'ADIB';
        $long = 'AHMAD FAUZI RAHMATULLAH ABDURRAHMAN';

        $boxedShort = $heightOf($boxed, $short);
        $boxedLong = $heightOf($boxed, $long);

        // The box is ~160px at 300 DPI. A two-line block at the requested size
        // would be far past it; shrunk, it has to come in under.
        $limit = (int) round((14 / 100) * 638);

        $this->assertLessThanOrEqual(
            $limit + 6, // a few pixels of descender tolerance
            $boxedLong,
            'a long name should be shrunk until its block fits the box',
        );

        // And the short name was left alone — same template, same box.
        $unboxed = $this->template($org, 85.6, 54, [
            [
                'key' => 'name', 'x' => 50, 'y' => 50, 'size' => 5,
                'align' => 'center', 'valign' => 'middle', 'wrap' => 60,
            ],
        ]);

        $this->assertEqualsWithDelta(
            $heightOf($unboxed, $short),
            $boxedShort,
            2,
            'a name that already fits must render at the size the organizer set',
        );
    }

    /**
     * A field with no `h` is never shrunk — the state every template written
     * before this feature is in.
     *
     * Compared against the same long name in a box, because "it rendered" says
     * nothing: a renderer that shrank everything would still draw something
     * here, just smaller than the template asked for.
     */
    public function test_a_text_field_without_a_box_renders_at_the_size_it_was_given(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $long = 'AHMAD FAUZI RAHMATULLAH ABDURRAHMAN';

        $base = [
            'key' => 'name', 'x' => 50, 'y' => 50, 'size' => 5,
            'align' => 'center', 'valign' => 'middle', 'w' => 60,
        ];

        $render = fn (array $field) => $this->decode(
            $this->service()->render(
                $this->template($org, 85.6, 54, [$field]),
                $this->person(['name' => $long]),
            ),
        );

        $heightOf = fn (ImageInterface $img) => (fn (array $r) => $r[1] - $r[0])(
            $this->inkRows($img, 0, 1011),
        );

        $without = $heightOf($render($base));
        $with = $heightOf($render([...$base, 'h' => 14]));

        // Unshrunk, the wrapped block is taller than the box it was not given.
        $this->assertGreaterThan(
            $with,
            $without,
            'without a box height nothing should be shrunk',
        );
    }

    /**
     * `w` narrows the wrap for a text field, and `wrap` still works for the
     * templates that predate it.
     *
     * The pair is the test: a renderer reading only `wrap` ignores every box
     * the editor now drags, and one reading only `w` silently widens every
     * template already saved to the full card.
     */
    public function test_a_text_box_width_wraps_the_same_way_the_old_wrap_percentage_did(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $long = 'AHMAD FAUZI RAHMATULLAH ABDURRAHMAN';

        $heightOf = fn (array $field) => (fn (array $r) => $r[1] - $r[0])($this->inkRows(
            $this->decode($this->service()->render(
                $this->template($org, 85.6, 54, [$field]),
                $this->person(['name' => $long]),
            )),
            0,
            1011,
        ));

        $base = ['key' => 'name', 'x' => 50, 'y' => 50, 'size' => 4, 'align' => 'center', 'valign' => 'middle'];

        // The same narrow figure, written each way, must wrap to the same
        // number of lines — hence the same block height.
        $this->assertEqualsWithDelta(
            $heightOf([...$base, 'wrap' => 40]),
            $heightOf([...$base, 'w' => 40]),
            2,
            '`w` and `wrap` should wrap a text field identically',
        );

        // And a narrow box really is narrower than a wide one, or the
        // assertion above would hold for a renderer that ignores both.
        $this->assertGreaterThan(
            $heightOf([...$base, 'w' => 90]),
            $heightOf([...$base, 'w' => 40]),
            'a narrower box should wrap to more lines',
        );
    }
}
