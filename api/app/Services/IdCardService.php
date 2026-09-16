<?php

namespace App\Services;

use App\Models\Event;
use App\Models\IdCardTemplate;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use Throwable;
use ZipArchive;

/**
 * Renders ID cards as PNG.
 *
 * Structurally the twin of CertificateService, but it shares no renderer with
 * it: certificates go through dompdf, cards are drawn with Intervention Image
 * on GD. That is why there is no JPEG re-encode step here — dompdf cannot read
 * WebP and everything we store is WebP, but GD can.
 *
 * Nothing in here writes a row. A card is purely a function of (person,
 * template), both of which outlive it; see the create_id_card_templates_table
 * migration for what that costs and why it is the right trade.
 */
class IdCardService
{
    /**
     * Where finished batch zips live.
     *
     * Deliberately *not* `id-cards/`: that prefix is where the template-form
     * uploads card backgrounds (`folder="id-cards"` → `UploadController`), and
     * those are permanent org assets. `id-cards:prune` deletes everything old
     * under this prefix, so it has to be a prefix nothing else writes to — an
     * extension check alone is a one-typo-deep guard in front of a delete loop.
     */
    public const BATCH_PREFIX = 'id-card-batches';

    public function __construct(protected R2StorageService $r2) {}

    /**
     * Everyone in an event who can be given a card, flattened into one shape so
     * the renderer never learns that three different tables fed it.
     *
     * The `type`/`id` pair is what a generate request sends back to pick a
     * subset; `photo_url` may be null in all three pools, which is what the
     * initial tile exists for.
     *
     * @return array<int, array{type: string, id: string, name: string, role_label: string, team_name: string, photo_url: ?string}>
     */
    public function recipients(Event $event): array
    {
        $teams = $event->teams()
            ->where('status', 'approved')
            ->with([
                'players' => fn ($q) => $q->where('is_active', true)->orderBy('full_name'),
                'officials' => fn ($q) => $q->orderBy('sort_order')->orderBy('full_name'),
            ])
            ->orderBy('name')
            ->get();

        $out = [];

        foreach ($teams as $team) {
            foreach ($team->players as $player) {
                $out[] = [
                    'type' => 'player',
                    'id' => $player->id,
                    'name' => $player->full_name,
                    'role_label' => 'Pemain',
                    'team_name' => $team->name,
                    'photo_url' => $player->photo_url,
                ];
            }

            foreach ($team->officials as $official) {
                $out[] = [
                    'type' => 'official',
                    'id' => $official->id,
                    // A team official may carry no role at all, and the stored
                    // key ("head_coach") is a slug nobody typed — Catalog hands
                    // back null for both, and "Ofisial" is what a card says then.
                    'role_label' => Catalog::officialRoleLabel($event->sport_type, $official->role) ?? 'Ofisial',
                    'name' => $official->full_name,
                    'team_name' => $team->name,
                    'photo_url' => $official->photo_url,
                ];
            }
        }

        foreach ($event->personnel()->orderBy('sort_order')->orderBy('full_name')->get() as $person) {
            $out[] = [
                'type' => 'personnel',
                'id' => $person->id,
                'name' => $person->full_name,
                // The fallback lives on the model, deliberately at read time —
                // an empty column means "no title", not "Wasit".
                'role_label' => $person->roleLabel(),
                'team_name' => '',
                'photo_url' => $person->photo_url,
            ];
        }

        return $out;
    }

    /**
     * The card artwork, decoded once per batch rather than once per card.
     *
     * Already scaled to the card's pixel size, so `render()` only has to clone
     * it. Null when the background cannot be fetched — the card still renders,
     * on white, which beats failing a 400-card batch over one dead URL.
     */
    public function background(IdCardTemplate $template): ?ImageInterface
    {
        $bytes = $this->fetchBytes($template->background_url);

        if ($bytes === null) {
            return null;
        }

        try {
            return $this->manager()
                ->decodeBinary($bytes)
                ->cover($this->px($template->width_mm), $this->px($template->height_mm));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * One card, as PNG bytes.
     *
     * @param  array{name?: string, role_label?: string, team_name?: string, photo_url?: ?string}  $person
     */
    public function render(IdCardTemplate $template, array $person, ?ImageInterface $background = null): string
    {
        $w = $this->px($template->width_mm);
        $h = $this->px($template->height_mm);

        $bg = $background ?? $this->background($template);
        $card = $bg ? clone $bg : $this->manager()->createImage($w, $h)->fill('ffffff');

        $values = [
            'name' => (string) ($person['name'] ?? ''),
            'role_label' => (string) ($person['role_label'] ?? ''),
            'team_name' => (string) ($person['team_name'] ?? ''),
            'event_name' => (string) ($person['event_name'] ?? ''),
        ];

        // Array order is z-order: the organizer arranged these, and redrawing
        // them in any other order would rearrange their overlaps.
        foreach ((array) $template->fields as $field) {
            if (($field['key'] ?? '') === 'photo') {
                $this->drawPhoto($card, $field, $person, $w, $h);

                continue;
            }

            $this->drawText($card, $field, $values[$field['key']] ?? '', $w, $h);
        }

        return (string) $card->encode(new PngEncoder);
    }

    /**
     * Render every recipient into one zip and park it in R2, returning the key.
     *
     * Written to the bucket rather than local disk because docker-compose gives
     * `worker` no volume shared with `api` — a zip on the worker's disk is
     * invisible to the container that serves the download.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     * @param  ?callable(int): void  $onProgress  called with the running count
     */
    public function zip(Event $event, IdCardTemplate $template, array $recipients, ?callable $onProgress = null): string
    {
        $background = $this->background($template);
        $path = tempnam(sys_get_temp_dir(), 'idcards').'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (array_values($recipients) as $i => $person) {
            $person['event_name'] = $event->name;

            // NNN- prefix, not the slug alone: two people really are called
            // "Ahmad Fauzi", and numbering makes a collision impossible without
            // a lookup while matching the order shown on screen.
            $name = sprintf('%03d-%s.png', $i + 1, Str::slug((string) ($person['name'] ?? '')) ?: 'kartu');

            $zip->addFromString($name, $this->render($template, $person, $background));

            // addFromString holds its payload in memory until the archive is
            // closed, so a 500-card batch would carry every PNG at once.
            if ($i % 25 === 24) {
                $zip->close();
                $zip->open($path);
            }

            if ($onProgress) {
                $onProgress($i + 1);
            }
        }

        $zip->close();

        $key = self::BATCH_PREFIX.'/'.Str::uuid()->toString().'.zip';
        $contents = (string) file_get_contents($path);

        // The same branch UploadController takes, and it is not optional here:
        // the `r2` disk is configured `throw => false, report => false`, so
        // without credentials this write would no-op *silently* and the download
        // would 404 on an object nothing ever complained about.
        if (config('r2.key')) {
            $this->r2->put($key, $contents, 'application/zip');
        } else {
            Storage::disk('public')->put($key, $contents);
        }

        @unlink($path);

        return $key;
    }

    /**
     * The disk a finished batch lives on.
     *
     * Mirrors MediaCleanupService::disk(): R2 when it is configured, the local
     * `public` disk in tests and in development. The download endpoint reads
     * through here rather than reaching for `r2` directly so the two halves
     * cannot disagree about where zip() just put the file.
     *
     * Typed as the adapter rather than the Filesystem contract because
     * `download()` is an adapter method — the contract has no response-building
     * on it, which is why MediaCleanupService::disk() can return the narrower type.
     */
    public function storage(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(config('r2.key') ? 'r2' : 'public');

        return $disk;
    }

    /** The filename the browser saves a batch under. */
    public function zipFilename(Event $event): string
    {
        return 'id-card-'.($event->slug ?: Str::slug($event->name)).'-'.now()->format('Ymd-His').'.zip';
    }

    /**
     * Millimetres to pixels at the configured DPI. 1 inch = 25.4 mm, so CR80
     * (85.6 x 54) is 1011 x 638 at 300.
     */
    public function px(float $mm): int
    {
        return (int) round($mm / 25.4 * (int) config('id_card.dpi'));
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $person
     */
    protected function drawPhoto(ImageInterface $card, array $field, array $person, int $w, int $h): void
    {
        // x/y is the box's top-left corner here, NOT the anchor point a text
        // field uses — a box resized from a corner has to be anchored at one.
        $boxW = max(1, (int) round(((float) ($field['w'] ?? 0) / 100) * $w));
        $boxH = max(1, (int) round(((float) ($field['h'] ?? 0) / 100) * $h));
        $x = (int) round(((float) ($field['x'] ?? 0) / 100) * $w);
        $y = (int) round(((float) ($field['y'] ?? 0) / 100) * $h);

        $bytes = ($person['photo_url'] ?? null) ? $this->fetchBytes((string) $person['photo_url']) : null;

        if ($bytes !== null) {
            try {
                $photo = $this->manager()->decodeBinary($bytes);
                $photo = ($field['fit'] ?? 'cover') === 'contain'
                    ? $photo->contain($boxW, $boxH)
                    : $photo->cover($boxW, $boxH);
            } catch (Throwable $e) {
                report($e);
                $photo = null;
            }
        } else {
            $photo = null;
        }

        $photo ??= $this->placeholder((string) ($person['name'] ?? ''), $boxW, $boxH);

        $radius = (float) ($field['radius'] ?? 0);

        if ($radius > 0) {
            // Percent of the SHORTER side, so a circle stays a circle on a box
            // that isn't square — the same rule the editor previews.
            $photo = $this->roundCorners($photo, (int) round(($radius / 100) * min($boxW, $boxH)));
        }

        // insert(), not place(): Intervention v4 has no place(). Its default
        // alignment is TOP_LEFT, which is exactly this field's anchor.
        $card->insert($photo, $x, $y);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    protected function drawText(ImageInterface $card, array $field, string $value, int $w, int $h): void
    {
        // An empty value leaves no hole: a card for someone with no club should
        // not print a blank line where the club goes.
        if (trim($value) === '') {
            return;
        }

        if (($field['uppercase'] ?? false)) {
            $value = Str::upper($value);
        }

        $align = (string) ($field['align'] ?? 'left');
        $bold = (bool) ($field['bold'] ?? false);
        $font = (string) config($bold ? 'id_card.fonts.bold' : 'id_card.fonts.regular');

        // size is millimetres, and px() is the whole conversion. Do NOT apply
        // the 0.76 factor visible in Gd/FontProcessor::nativeFontSize() — that
        // is Intervention's own px-to-pt step for imagettftext, which means
        // ->size() already takes pixels.
        $size = $this->px((float) ($field['size'] ?? 4));
        $wrap = (int) round(((float) ($field['wrap'] ?? 100) / 100) * $w);

        $card->text(
            $value,
            (int) round(((float) ($field['x'] ?? 0) / 100) * $w),
            (int) round(((float) ($field['y'] ?? 0) / 100) * $h),
            function (FontFactory $f) use ($font, $size, $field, $align, $wrap) {
                $f->filename($font);
                $f->size($size);
                $f->color((string) ($field['color'] ?? '#111827'));
                // Vertical align must be stated: Font defaults to BOTTOM, and
                // every line of the layout would ride up by its own height.
                $f->align($align, 'top');
                $f->lineHeight(1.2);
                $f->wrap($wrap);
            },
        );
    }

    /**
     * The tile shown where a photo is missing: the person's initials on a flat
     * colour picked by the same hash crestGradient() uses in the frontend, so
     * one person is the same colour in the bracket and on their card.
     *
     * GD has no gradients, so this takes the first stop of each pair.
     *
     * Caveat, and it is accepted rather than fixed: PHP's ord() walks bytes
     * while JS charCodeAt() walks UTF-16 code units, so a non-ASCII name hashes
     * differently on the two sides. The colour is decoration, and matching it
     * exactly would mean implementing UTF-16 in PHP.
     */
    protected function placeholder(string $name, int $w, int $h): ImageInterface
    {
        $colours = ['#1E6FFF', '#059669', '#DC2626', '#D97706', '#7C3AED', '#0EA5E9'];

        $hash = 0;
        foreach (str_split($name !== '' ? $name : '?') as $char) {
            // & 0xFFFFFFFF mirrors the >>> 0 that keeps the JS side in 32 bits.
            $hash = ($hash * 31 + ord($char)) & 0xFFFFFFFF;
        }

        $tile = $this->manager()->createImage($w, $h)->fill($colours[$hash % count($colours)]);

        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        if ($initials !== '') {
            $tile->text($initials, (int) round($w / 2), (int) round($h / 2), function (FontFactory $f) use ($w, $h) {
                $f->filename((string) config('id_card.fonts.bold'));
                $f->size((int) round(min($w, $h) * 0.42));
                $f->color('#ffffff');
                // 'center', not 'middle': Font::setAlignmentVertical() goes
                // through Alignment::from(), which only knows the enum's own
                // values — and the enum has no 'middle'.
                $f->align('center', 'center');
            });
        }

        return $tile;
    }

    /**
     * Punch transparent corners into an image.
     *
     * The one place this service drops to raw GD: Intervention v4 draws
     * rectangles, ellipses, circles, polygons, lines and beziers — there is no
     * rounded-rect primitive to build on.
     *
     * Only the four r x r corner squares are walked (~14k pixels at r=60), so
     * this stays cheap even at 300 DPI. The edges are aliased; at print density
     * that is not visible. If it ever is, render the photo at 2x and scale down
     * rather than reaching for a supersample everywhere.
     */
    protected function roundCorners(ImageInterface $image, int $radius): ImageInterface
    {
        if ($radius < 1) {
            return $image;
        }

        $gd = $image->core()->native();
        $w = imagesx($gd);
        $h = imagesy($gd);
        $radius = min($radius, (int) floor(min($w, $h) / 2));

        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        $transparent = imagecolorallocatealpha($gd, 0, 0, 0, 127);

        // Each corner's circle centre sits `radius` in from both its edges;
        // anything in the corner square further than `radius` from it is outside
        // the rounded shape.
        $corners = [
            [0, 0, $radius, $radius],
            [$w - $radius, 0, $w - $radius - 1, $radius],
            [0, $h - $radius, $radius, $h - $radius - 1],
            [$w - $radius, $h - $radius, $w - $radius - 1, $h - $radius - 1],
        ];

        foreach ($corners as [$startX, $startY, $centreX, $centreY]) {
            for ($x = $startX; $x < $startX + $radius; $x++) {
                for ($y = $startY; $y < $startY + $radius; $y++) {
                    $dx = $x - $centreX;
                    $dy = $y - $centreY;

                    if (($dx * $dx + $dy * $dy) > ($radius * $radius)) {
                        imagesetpixel($gd, $x, $y, (int) $transparent);
                    }
                }
            }
        }

        return $image;
    }

    /**
     * Bytes behind a stored image URL.
     *
     * Our own uploads are read straight off the disk they live on, because
     * going back out through the public host would be a TLS round trip per card
     * and the container cannot verify that certificate.
     *
     * CertificateService only tries the R2 base, which is right for it: PDFs are
     * only ever rendered where R2 is configured. This one is exercised by tests
     * and by `bun run dev`, where uploads land on the local `public` disk and
     * carry an `http://localhost/storage/...` URL that no HTTP client in the
     * test process can fetch — hence both bases, the same pair
     * MediaCleanupService::keyFor() walks.
     */
    protected function fetchBytes(string $url): ?string
    {
        try {
            foreach ([config('r2.public_url'), Storage::disk('public')->url('')] as $base) {
                $base = rtrim((string) $base, '/');

                if ($base !== '' && str_starts_with($url, $base.'/')) {
                    $key = ltrim(Str::after($url, $base), '/');

                    return $this->storage()->exists($key) ? $this->storage()->get($key) : null;
                }
            }

            $response = Http::timeout(15)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function manager(): ImageManager
    {
        return new ImageManager(new GdDriver);
    }
}
