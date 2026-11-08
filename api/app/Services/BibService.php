<?php

namespace App\Services;

use App\Models\BibTemplate;
use App\Models\Event;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
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
 * Assigns race numbers and renders e-BIBs as PNG.
 *
 * Structurally the twin of IdCardService — same GD renderer shape, same batch
 * discipline, same three-prefix storage rule — with two differences that run
 * all the way down:
 *
 *   It writes no rows of its own. `race_entries` is already the durable row a
 *     bib belongs to, carrying the number, the credential and the address, so
 *     there is no `bibs` table to mint. See create_bib_templates_table for why
 *     that absence is permanent rather than deferred.
 *   Its QR encodes `race_entries.qr_code` — the check-in credential
 *     RaceScanController already scans — rather than a verification URL. An
 *     e-BIB IS the race-pack ticket, which is why only a paid entry gets one:
 *     markPaid() is what mints that column.
 *
 * This service is also the first writer `race_entries.bib_number` has ever had.
 * The column shipped with the fun run and was deliberately left unwritten
 * (docs/fun-run-progress.md) because the shape could not be decided without
 * knowing how a real organizer splits a race pack. It is decided here:
 * sequential per category, assigned once, never reassigned.
 */
class BibService
{
    /**
     * Where finished batch zips live.
     *
     * Its own prefix, not a second use of `id-card-batches`: `bibs:prune`
     * deletes everything old under this one, and a sweep that reached the card
     * feature's zips would be a delete loop guarded by nothing but the fact
     * that nobody had looked yet. Same reasoning IdCardService::BATCH_PREFIX
     * spells out against `id-cards/`.
     */
    public const BATCH_PREFIX = 'bib-batches';

    /**
     * Where the per-runner PNGs live, one object per printed bib.
     *
     * Permanent, unlike BATCH_PREFIX which the prune sweep empties on the
     * download TTL: `race_entries.bib_png_key` names each one, the runner
     * downloads it from their own proof page, and the email attachment reads
     * it. The prune sweep must never reach them.
     */
    public const PRINT_PREFIX = 'bib-prints';

    public function __construct(protected R2StorageService $r2) {}

    /**
     * Give every unnumbered paid entry in a category its race number.
     *
     * > **Invariant: a number is assigned once and then never changes.** Only
     * > rows whose `bib_number` is null are touched, and the UPDATE carries
     * > `whereNull('bib_number')` *inside* it rather than relying on the read
     * > above — the same shape EventController::store() uses to claim a credit.
     * > Two concurrent batches would otherwise overwrite each other and send two
     * > runners to the start line wearing the same number, which no later screen
     * > can detect. Reassigning is just as bad for a different reason: the
     * > number is printed on paper that has already been handed out.
     *
     * Sequential per CATEGORY, not per event: "5K-001" and "10K-001" are two
     * runners, and that is how a start list reads. The sequence continues from
     * the highest number already in the category rather than from the row
     * count, so entries the organizer numbered by hand are not walked over.
     *
     * No monthly reset, unlike the certificate and card sequences: a race is
     * one morning, and a number restarting mid-event would collide with itself.
     *
     * @return int how many rows were newly numbered
     */
    public function assignNumbers(RaceCategory $category): int
    {
        $prefix = $this->numberPrefix($category);

        return DB::transaction(function () use ($category, $prefix) {
            // Lock the highest row of this category's sequence rather than a
            // max(): Postgres rejects FOR UPDATE alongside an aggregate. Same
            // scheme as CertificateService::nextNumber().
            $last = $category->entries()
                ->whereNotNull('bib_number')
                ->where('bib_number', 'like', $prefix.'%')
                ->orderByDesc('bib_number')
                ->lockForUpdate()
                ->value('bib_number');

            $seq = $last ? (int) Str::afterLast((string) $last, '-') : 0;

            // Paid only, and ordered so the numbering follows the order runners
            // actually entered rather than whatever the planner returns.
            $unnumbered = $category->entries()
                ->where('status', 'paid')
                ->whereNull('bib_number')
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            $assigned = 0;

            foreach ($unnumbered as $id) {
                $seq++;

                // whereNull inside the UPDATE, not just in the read above: on
                // the losing side of a race this matches nothing and the
                // winner's number stands.
                $written = RaceEntry::whereKey($id)
                    ->whereNull('bib_number')
                    ->update(['bib_number' => sprintf('%s-%03d', $prefix, $seq)]);

                if ($written === 0) {
                    // Somebody else numbered this row. Give the sequence value
                    // back so the next runner does not skip a number.
                    $seq--;

                    continue;
                }

                $assigned++;
            }

            return $assigned;
        });
    }

    /**
     * Number every unnumbered paid entry across the whole event, category by
     * category.
     *
     * Called before a batch is built rather than offered as a button of its
     * own, which is the practical half of "the generator is the writer": a
     * sheet whose number field prints nothing is the one outcome an organizer
     * cannot use, and asking them to press two buttons in the right order is
     * asking them to find that out the hard way. Idempotent, because
     * assignNumbers() is — so running it on every print costs nothing and
     * covers runners who entered since the last one.
     *
     * Per category in a loop rather than one query over the event: each
     * category owns its own sequence, and that is the rule assignNumbers()
     * exists to keep.
     *
     * @return int how many rows were newly numbered
     */
    public function assignNumbersForEvent(Event $event): int
    {
        $assigned = 0;

        foreach ($event->raceCategories()->orderBy('sort_order')->orderBy('name')->get() as $category) {
            $assigned += $this->assignNumbers($category);
        }

        return $assigned;
    }

    /**
     * The stem a category's numbers are built on: "5K", "UMUM".
     *
     * Derived from the slug rather than stored, because it is presentation of
     * something already on the row — and derived at ASSIGN time only, never
     * read back. Once `bib_number` holds "5K-001", renaming the category leaves
     * that string exactly as it was printed, the same snapshot discipline as
     * `period_name` beside `price`.
     *
     * Falls back to "BIB" for a slug that uppercases to nothing, so a category
     * named in a script this normaliser cannot fold still produces numbers.
     */
    protected function numberPrefix(RaceCategory $category): string
    {
        $stem = Str::upper(preg_replace('/[^A-Za-z0-9]+/', '', (string) $category->slug) ?? '');

        return $stem !== '' ? $stem : 'BIB';
    }

    /**
     * Every runner in this event who can be handed a bib, flattened into one
     * shape so the renderer never learns which table fed it.
     *
     * PAID ONLY, and that is the gate rather than a filter the caller may skip:
     * an unpaid entry has no `qr_code`, so its bib would carry a blank square
     * where the scannable half goes — a race-pack ticket that admits nobody and
     * looks exactly like one that works. The unpaid runner has a proof page
     * telling them what is outstanding; a printed bib would contradict it.
     *
     * `email` comes from a column here, not a relation. This is the whole
     * reason there is no groupForSending() twin: IdCardService needs one because
     * three pools answer "whose address?" two different ways, while a runner
     * holds their own and one entry means one mail.
     *
     * @return array<int, array{id: string, name: string, bib_number: ?string, category_name: string, qr_code: ?string, email: ?string}>
     */
    public function recipients(Event $event): array
    {
        $entries = $event->raceEntries()
            ->where('status', 'paid')
            ->with('category:id,name,sort_order')
            ->get()
            ->sortBy([
                fn (RaceEntry $e) => $e->category?->sort_order ?? 0,
                fn (RaceEntry $e) => $e->bib_number ?? '',
                fn (RaceEntry $e) => $e->full_name,
            ])
            ->values();

        return $entries->map(fn (RaceEntry $entry) => [
            'id' => $entry->id,
            'name' => $entry->full_name,
            'bib_number' => $entry->bib_number,
            'category_name' => $entry->category?->name ?? '',
            'qr_code' => $entry->qr_code,
            'email' => $entry->email,
        ])->all();
    }

    /**
     * The bib artwork, decoded once per batch rather than once per runner.
     *
     * Already scaled to the sheet's pixel size, so `render()` only has to clone
     * it. Null when the background cannot be fetched — the bib still renders,
     * on white, which beats failing a 400-runner batch over one dead URL.
     */
    public function background(BibTemplate $template): ?ImageInterface
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
     * One bib, as PNG bytes.
     *
     * @param  array{name?: string, bib_number?: ?string, category_name?: string, event_name?: string, qr_code?: ?string}  $person
     */
    public function render(BibTemplate $template, array $person, ?ImageInterface $background = null): string
    {
        $w = $this->px($template->width_mm);
        $h = $this->px($template->height_mm);

        $bg = $background ?? $this->background($template);
        $bib = $bg ? clone $bg : $this->manager()->createImage($w, $h)->fill('ffffff');

        $values = [
            'bib_number' => (string) ($person['bib_number'] ?? ''),
            'name' => (string) ($person['name'] ?? ''),
            'category_name' => (string) ($person['category_name'] ?? ''),
            'event_name' => (string) ($person['event_name'] ?? ''),
        ];

        // Array order is z-order: the organizer arranged these, and redrawing
        // them in any other order would rearrange their overlaps.
        foreach ((array) $template->fields as $field) {
            if (($field['key'] ?? '') === 'qr') {
                $code = (string) ($person['qr_code'] ?? '');

                // An entry with no credential draws nothing rather than an
                // empty frame, the same way a text field with no value draws
                // nothing. recipients() already refuses unpaid entries, so
                // reaching here means a paid row whose code has not been minted
                // yet — and a blank square beats a QR that scans as nothing.
                if ($code !== '') {
                    $this->drawQr($bib, $field, $code, $w, $h);
                }

                continue;
            }

            $this->drawText($bib, $field, $values[$field['key']] ?? '', $w, $h);
        }

        return (string) $bib->encode(new PngEncoder);
    }

    /**
     * Render every recipient into one zip and park it in R2, returning the key.
     *
     * Written to the bucket rather than local disk because docker-compose gives
     * `worker` no volume shared with `api` — a zip on the worker's disk is
     * invisible to the container that serves the download.
     *
     * Each bib is rendered once and used twice: it goes into the zip *and* is
     * parked under PRINT_PREFIX as its own object. Rendering is the expensive
     * half (GD, minutes for a full batch), so re-rendering when someone emails
     * the batch would double it — and slicing one sheet back out of a 400-bib
     * zip to attach it would mean downloading 400 to send one. It is also what
     * the runner's own download reads.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     * @param  ?callable(int): void  $onProgress  called with the running count
     */
    public function zip(Event $event, BibTemplate $template, array $recipients, ?callable $onProgress = null): string
    {
        $background = $this->background($template);
        $path = tempnam(sys_get_temp_dir(), 'bibs').'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (array_values($recipients) as $i => $person) {
            $person['event_name'] = $event->name;

            $png = $this->render($template, $person, $background);

            if (isset($person['id'])) {
                $this->storePrint((string) $person['id'], $png);
            }

            // The bib number is in the filename, but an NNN- prefix leads it
            // anyway: a runner whose number has not been assigned yet would
            // otherwise collide with every other such runner, and two people
            // really are called "Ahmad Fauzi". The prefix also matches the
            // order shown on screen.
            $name = sprintf(
                '%03d-%s-%s.png',
                $i + 1,
                Str::slug((string) ($person['bib_number'] ?? '')) ?: 'tanpa-nomor',
                Str::slug((string) ($person['name'] ?? '')) ?: 'pelari',
            );

            $zip->addFromString($name, $png);

            // addFromString holds its payload in memory until the archive is
            // closed, so a 500-bib batch would carry every PNG at once.
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

        $this->put($key, (string) file_get_contents($path), 'application/zip');

        @unlink($path);

        return $key;
    }

    /**
     * Park one runner's PNG under PRINT_PREFIX and record the key on their
     * entry.
     *
     * Keyed on the entry id, so a reprint overwrites the same object rather
     * than leaving the old render behind under a key nothing names.
     *
     * Written with a bare UPDATE rather than through the model: this is the one
     * column of `race_entries` this service owns, and `$entry->update()` would
     * swallow anything else that happened to be dirty on an instance the
     * renderer was handed. Every money and status column on that table has
     * exactly one writer, and this must not become a second door onto any of
     * them.
     */
    public function storePrint(string $entryId, string $png): void
    {
        $key = self::PRINT_PREFIX.'/'.$entryId.'.png';

        $this->put($key, $png, 'image/png');

        RaceEntry::whereKey($entryId)->update(['bib_png_key' => $key]);
    }

    /**
     * Write an object to whichever disk this service reads from.
     *
     * The same branch UploadController takes, and it is not optional: the `r2`
     * disk is configured `throw => false, report => false`, so without
     * credentials a write through it would no-op *silently* and the download
     * would 404 on an object nothing ever complained about. Spelled once because
     * two writers (the zip and the per-runner prints) must not disagree with
     * storage() about where they put things.
     */
    protected function put(string $key, string $contents, string $contentType): void
    {
        if (self::diskName() === 'r2') {
            $this->r2->put($key, $contents, $contentType);
        } else {
            Storage::disk('public')->put($key, $contents);
        }
    }

    /**
     * The disk a finished batch lives on.
     *
     * Mirrors IdCardService::storage(): R2 when it is configured, the local
     * `public` disk in tests and in development. The download endpoints read
     * through here rather than reaching for `r2` directly so the two halves
     * cannot disagree about where zip() just put the file.
     */
    public function storage(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(self::diskName());

        return $disk;
    }

    /**
     * The disk name, for the one caller that needs the name rather than the
     * adapter: `Attachment::fromStorageDisk()` in BibIssuedMail takes a string.
     * Static because a Mailable is serialized onto the queue and must not carry
     * a service with it.
     *
     * Spelled here, not there — three readers of "R2 or local?" (this, put(),
     * and the mail attachment) that disagree produce a mail with no attachment,
     * which sends successfully and stamps `bib_sent_at`.
     */
    public static function diskName(): string
    {
        return config('r2.key') ? 'r2' : 'public';
    }

    /** The filename the browser saves a batch under. */
    public function zipFilename(Event $event): string
    {
        return 'e-bib-'.($event->slug ?: Str::slug($event->name)).'-'.now()->format('Ymd-His').'.zip';
    }

    /** The filename one runner's sheet is attached and downloaded under. */
    public function filename(RaceEntry $entry): string
    {
        $stem = Str::slug((string) ($entry->bib_number ?: $entry->full_name)) ?: 'e-bib';

        return 'e-bib-'.$stem.'.png';
    }

    /**
     * Millimetres to pixels at the configured DPI. 1 inch = 25.4 mm, so A5
     * landscape (210 x 148) is 2480 x 1748 at 300.
     */
    public function px(float $mm): int
    {
        return (int) round($mm / 25.4 * (int) config('bib.dpi'));
    }

    /**
     * The check-in QR, drawn module by module straight onto the sheet.
     *
     * Lifted from IdCardService::drawQr() rather than shared with it, because
     * the two differ in what they encode and that is the only thing a shared
     * helper could not parameterise away safely: this one carries a raw
     * credential, that one a URL. The two details that decide whether the
     * printed code actually scans are identical and both are load-bearing:
     *
     *   Integer module size. `intdiv(side, modules)`, never the float: a module
     *     7.4px wide gets drawn alternately 7 and 8 px, which walks the finder
     *     patterns out of square. The leftover pixels become margin instead.
     *   An explicit quiet zone. Four modules of white are drawn here, because a
     *     QR butted against background artwork does not scan.
     *
     * The box is squared off its shorter side rather than stretched: `w != h`
     * leaves slack, because a non-square QR is an unreadable one.
     *
     * @param  array<string, mixed>  $field
     */
    protected function drawQr(ImageInterface $bib, array $field, string $payload, int $w, int $h): void
    {
        $boxW = max(1, (int) round(((float) ($field['w'] ?? 0) / 100) * $w));
        $boxH = max(1, (int) round(((float) ($field['h'] ?? 0) / 100) * $h));
        $x = (int) round(((float) ($field['x'] ?? 0) / 100) * $w);
        $y = (int) round(((float) ($field['y'] ?? 0) / 100) * $h);

        try {
            // ISO-8859-1 rather than UTF-8: a check-in code is ASCII, and the
            // UTF-8 path prefixes an ECI segment that some scanners refuse (the
            // warning is in Encoder::encode() itself).
            $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'ISO-8859-1')->getMatrix();
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $modules = $matrix->getWidth();

        // Quiet zone counted in modules, so it scales with the code rather than
        // being a pixel figure that vanishes on a small box.
        $quiet = 4;
        $side = min($boxW, $boxH);
        $scale = intdiv($side, $modules + $quiet * 2);

        if ($scale < 1) {
            // Smaller than one pixel per module. Drawing it would produce a grey
            // smudge that looks like a working QR in a thumbnail and scans as
            // nothing at all; the editor is what warns about the size.
            return;
        }

        $drawn = ($modules + $quiet * 2) * $scale;

        // Centred in whatever the organizer drew, so the rounding above never
        // shifts the code towards one corner of its box.
        $originX = $x + intdiv($boxW - $drawn, 2);
        $originY = $y + intdiv($boxH - $drawn, 2);

        $plate = $this->manager()->createImage($drawn, $drawn)->fill('ffffff');
        $gd = $plate->core()->native();
        $black = (int) imagecolorallocate($gd, 0, 0, 0);

        for ($mx = 0; $mx < $modules; $mx++) {
            for ($my = 0; $my < $modules; $my++) {
                if ($matrix->get($mx, $my) !== 1) {
                    continue;
                }

                $px = ($quiet + $mx) * $scale;
                $py = ($quiet + $my) * $scale;

                imagefilledrectangle($gd, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
            }
        }

        $bib->insert($plate, $originX, $originY);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    protected function drawText(ImageInterface $bib, array $field, string $value, int $w, int $h): void
    {
        // An empty value leaves no hole: a sheet for a runner whose number has
        // not been assigned should not print a blank line where it goes.
        if (trim($value) === '') {
            return;
        }

        if (($field['uppercase'] ?? false)) {
            $value = Str::upper($value);
        }

        $align = (string) ($field['align'] ?? 'left');
        $bold = (bool) ($field['bold'] ?? false);
        $font = (string) config($bold ? 'bib.fonts.bold' : 'bib.fonts.regular');

        // size is millimetres, and px() is the whole conversion. Do NOT apply
        // the 0.76 factor visible in Gd/FontProcessor::nativeFontSize() — that
        // is Intervention's own px-to-pt step for imagettftext, which means
        // ->size() already takes pixels.
        $size = $this->px((float) ($field['size'] ?? 4));
        $wrap = (int) round(((float) ($field['wrap'] ?? 100) / 100) * $w);

        $bib->text(
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
     * Bytes behind a stored image URL.
     *
     * Our own uploads are read straight off the disk they live on, because
     * going back out through the public host would be a TLS round trip per bib
     * and the container cannot verify that certificate. Both bases are tried,
     * the same pair IdCardService::fetchBytes() walks: in tests and under
     * `bun run dev` uploads land on the local `public` disk and carry an
     * `http://localhost/storage/...` URL no HTTP client in the test process can
     * fetch.
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
