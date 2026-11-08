<?php

namespace Tests\Feature;

use App\Models\BibTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\BibService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The e-BIB renderer itself: GD, one sheet at a time, no HTTP and no queue.
 *
 * Every case compares two renders, for the reason IdCardRenderTest states: "it
 * didn't throw" is the failure mode this whole file exists to catch. A renderer
 * that quietly skips the QR, or one that hardcodes A5, passes any single-state
 * assertion.
 */
class BibRenderTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // tests/bootstrap.php blanks the R2 credentials, so BibService reads
        // and writes through the `public` disk — the same branch `bun run dev`
        // takes. Faking it keeps the background images out of the repo.
        Storage::fake('public');
    }

    private function service(): BibService
    {
        return app(BibService::class);
    }

    /** A real PNG on the fake disk, returned as the URL a template row stores. */
    private function image(string $key, int $w, int $h, string $colour): string
    {
        $png = (new ImageManager(new GdDriver))->createImage($w, $h)->fill($colour)->encode(new PngEncoder);

        Storage::disk('public')->put($key, (string) $png);

        return Storage::disk('public')->url($key);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $fields
     */
    private function template(Organization $org, float $w, float $h, ?array $fields = null): BibTemplate
    {
        return $org->bibTemplates()->create([
            'name' => 'BIB',
            'background_url' => $this->image('bibs/bg-'.uniqid().'.png', 600, 420, '#ffffff'),
            'width_mm' => $w,
            'height_mm' => $h,
            'fields' => $fields ?? [
                ['key' => 'bib_number', 'x' => 50, 'y' => 25, 'size' => 50, 'align' => 'center', 'bold' => true],
                ['key' => 'name', 'x' => 50, 'y' => 70, 'size' => 10, 'align' => 'center'],
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
            'id' => (string) Str::uuid(),
            'name' => 'Ahmad Fauzi',
            'bib_number' => '5K-001',
            'category_name' => '5K',
            'event_name' => 'Fun Run 2026',
            'qr_code' => 'RUNABCD1234',
            ...$overrides,
        ];
    }

    private function decode(string $png): ImageInterface
    {
        return (new ImageManager(new GdDriver))->decodeBinary($png);
    }

    /** How many pixels of the sheet are dark — the only cheap "was it drawn?". */
    private function darkPixels(ImageInterface $image): int
    {
        $small = $image->scaleDown(width: 300);
        $count = 0;

        for ($x = 0; $x < $small->width(); $x += 2) {
            for ($y = 0; $y < $small->height(); $y += 2) {
                // Channel values rather than a named accessor: colorAt() hands
                // back a Color of whatever colorspace the image is in, and the
                // Rgb/Cmyk classes do not share a red()/green()/blue() trio.
                //
                // Sliced to the first three, which is load-bearing: a PNG with
                // transparency yields a fourth alpha channel at 255, and
                // including it makes max() 255 for every pixel on the sheet —
                // so every count comes back 0 and every comparison in this file
                // passes by drawing nothing.
                $channels = array_map(
                    fn ($channel) => $channel->value(),
                    array_slice($small->colorAt($x, $y)->channels(), 0, 3),
                );

                if (max($channels) < 100) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function test_the_png_matches_the_sheet_size_at_the_configured_dpi(): void
    {
        config(['bib.dpi' => 300]);

        $org = $this->orgFor(User::factory()->create());

        $a5 = $this->decode($this->service()->render(
            $this->template($org, 210, 148),
            $this->person(),
        ));

        $a6 = $this->decode($this->service()->render(
            $this->template($org, 105, 148),
            $this->person(),
        ));

        // Both sizes, never one: a renderer that hardcoded A5 — or that simply
        // handed back the background untouched — passes the first pair on its
        // own. The second is narrower, so even a transposed conversion shows up.
        $this->assertSame([2480, 1748], [$a5->width(), $a5->height()]);
        $this->assertSame([1240, 1748], [$a6->width(), $a6->height()]);
    }

    /**
     * Invariant: the QR is drawn, and comparing against a template without one
     * is the only way to know. A `qr` field the renderer silently skipped
     * passes any assertion about the sheet existing.
     */
    public function test_a_template_with_a_qr_draws_more_than_one_without(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $plain = [
            ['key' => 'bib_number', 'x' => 50, 'y' => 25, 'size' => 50, 'align' => 'center', 'bold' => true],
        ];

        $withQr = [
            ...$plain,
            ['key' => 'qr', 'x' => 75, 'y' => 55, 'w' => 20, 'h' => 20],
        ];

        $without = $this->decode($this->service()->render($this->template($org, 210, 148, $plain), $this->person()));
        $with = $this->decode($this->service()->render($this->template($org, 210, 148, $withQr), $this->person()));

        $this->assertGreaterThan(
            $this->darkPixels($without),
            $this->darkPixels($with),
            'The qr field drew nothing.',
        );
    }

    /**
     * An entry with no credential draws no QR rather than an empty frame.
     *
     * Compared against the same template WITH a code, because asserting that
     * the codeless sheet rendered passes identically on a renderer that drew a
     * grey smudge — or one that threw the whole batch away.
     */
    public function test_an_entry_without_a_qr_code_draws_no_qr(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $template = $this->template($org, 210, 148, [
            ['key' => 'qr', 'x' => 40, 'y' => 30, 'w' => 25, 'h' => 25],
        ]);

        $coded = $this->decode($this->service()->render($template, $this->person()));
        $blank = $this->decode($this->service()->render($template, $this->person(['qr_code' => null])));

        $this->assertGreaterThan(0, $this->darkPixels($coded));
        $this->assertSame(0, $this->darkPixels($blank));
    }

    /**
     * Invariant: the QR encodes the CHECK-IN CODE, so two runners get two
     * different codes on the sheet.
     *
     * Asserting "a QR was drawn" cannot tell a renderer that encodes the code
     * from one that encodes a constant — and a constant would send every
     * runner's sheet through the same gate record.
     */
    public function test_two_runners_get_visibly_different_qr_codes(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $template = $this->template($org, 210, 148, [
            ['key' => 'qr', 'x' => 40, 'y' => 30, 'w' => 25, 'h' => 25],
        ]);

        $a = $this->service()->render($template, $this->person(['qr_code' => 'RUNAAAA1111']));
        $b = $this->service()->render($template, $this->person(['qr_code' => 'RUNBBBB2222']));

        $this->assertNotSame($a, $b);
    }

    /**
     * A missing value leaves no hole: an entry printed before its number was
     * assigned prints nothing where the number goes rather than a blank line.
     *
     * The pair matters — a renderer that drew nothing at all for either would
     * pass the second assertion on its own.
     */
    public function test_an_unassigned_number_prints_nothing_where_the_number_goes(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $template = $this->template($org, 210, 148, [
            ['key' => 'bib_number', 'x' => 50, 'y' => 30, 'size' => 50, 'align' => 'center', 'bold' => true],
        ]);

        $numbered = $this->decode($this->service()->render($template, $this->person()));
        $unnumbered = $this->decode($this->service()->render($template, $this->person(['bib_number' => null])));

        $this->assertGreaterThan(0, $this->darkPixels($numbered));
        $this->assertSame(0, $this->darkPixels($unnumbered));
    }

    /**
     * The background is drawn, not ignored.
     *
     * Two different artworks must produce two different sheets — a renderer
     * that fell back to white on every fetch passes every other case in this
     * file, because none of them look at the backdrop.
     */
    public function test_the_background_artwork_reaches_the_sheet(): void
    {
        $org = $this->orgFor(User::factory()->create());

        $fields = [['key' => 'bib_number', 'x' => 50, 'y' => 30, 'size' => 20, 'align' => 'center']];

        $red = $org->bibTemplates()->create([
            'name' => 'Merah',
            'background_url' => $this->image('bibs/red.png', 600, 420, '#ff0000'),
            'width_mm' => 210,
            'height_mm' => 148,
            'fields' => $fields,
        ]);

        $blue = $org->bibTemplates()->create([
            'name' => 'Biru',
            'background_url' => $this->image('bibs/blue.png', 600, 420, '#0000ff'),
            'width_mm' => 210,
            'height_mm' => 148,
            'fields' => $fields,
        ]);

        $onRed = $this->decode($this->service()->render($red, $this->person()));
        $onBlue = $this->decode($this->service()->render($blue, $this->person()));

        $corner = fn (ImageInterface $i) => $i->colorAt(5, 5)->toHex();

        $this->assertNotSame($corner($onRed), $corner($onBlue));
    }
}
