<?php

namespace Tests\Feature;

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
}
