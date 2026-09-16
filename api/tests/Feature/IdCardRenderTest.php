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
}
