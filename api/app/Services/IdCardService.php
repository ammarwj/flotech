<?php

namespace App\Services;

use App\Models\Event;
use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Support\TemplateFields;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Alignment;
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
 * This service DOES write rows now, and the create_id_card_templates_table
 * migration anticipated it: a card used to be a pure function of (person,
 * template), which is why there was no table and no QR field. A scannable card
 * needs a number to encode and a page that answers it, so `id_cards` supplies
 * both — see create_id_cards_table.
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

    /**
     * Where the per-card PNGs live, one object per issued row.
     *
     * A third prefix rather than a second use of either existing one, and the
     * distinction is load-bearing in both directions. `id-cards/` holds template
     * backgrounds, which are permanent org assets. BATCH_PREFIX holds zips,
     * which `id-cards:prune` deletes wholesale on the download TTL. These are
     * permanent like the first and generated like the second — an `id_cards` row
     * names each one and its QR has already been handed out, so the prune sweep
     * must never reach them.
     */
    public const PRINT_PREFIX = 'id-card-prints';

    public function __construct(protected R2StorageService $r2) {}

    /**
     * Everyone in an event who can be given a card, flattened into one shape so
     * the renderer never learns that three different tables fed it.
     *
     * The `type`/`id` pair is what a generate request sends back to pick a
     * subset; `photo_url` may be null in all three pools, which is what the
     * initial tile exists for.
     *
     * `email` is where a card can be sent, and the three pools answer it very
     * differently: `players` and `team_officials` have no email column at all,
     * so for both it is the team manager's account address — the same single
     * address CertificateService::issueBatch() has to settle for, and the reason
     * one email carries a whole team's cards. Event personnel carry their own.
     * Null is ordinary, not an error: a team registered without a manager
     * account has nobody to send to.
     *
     * `values` is everything else a template may print for this person, already
     * resolved to the strings that go on the card: the columns the row carries
     * (jersey, position, date of birth) and the answers they gave to the
     * event's registration form, keyed exactly as a template places them.
     *
     * Resolved HERE rather than in render(), and that is the whole reason the
     * key exists. render() is handed one flat person array and no query budget;
     * re-deriving the answers there would mean a lookup per card, which on a
     * 400-card reprint is 400 round trips for rows this method already has in
     * hand. It also means the pool a generate request is filtered against and
     * the values that get printed come out of one pass, so a person the
     * controller accepted can never be a person the renderer has nothing for.
     *
     * @return array<int, array{type: string, id: string, name: string, role_label: string, team_name: string, photo_url: ?string, email: ?string, values: array<string, string>}>
     */
    public function recipients(Event $event): array
    {
        $teams = $event->teams()
            ->where('status', 'approved')
            ->with([
                'manager:id,email',
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
                    'email' => $team->manager?->email,
                    'values' => [
                        'jersey_number' => (string) ($player->jersey_number ?? ''),
                        'position' => TemplateFields::positionLabel($event->sport_type, $player->position),
                        'date_of_birth' => $player->date_of_birth
                            ? Carbon::parse($player->date_of_birth)->translatedFormat('d F Y')
                            : '',
                        ...TemplateFields::values($event, [
                            'team' => $team->custom_fields,
                            'player' => $player->custom_fields,
                        ]),
                    ],
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
                    'email' => $team->manager?->email,
                    'values' => TemplateFields::values($event, [
                        'team' => $team->custom_fields,
                        'official' => $official->custom_fields,
                    ]),
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
                // Their own, not a manager's: personnel belong to no team. This
                // is why `email` is resolved in recipients() rather than by the
                // mail job — one pool answers it from a column, two from a
                // relation, and a second reader would have to re-derive which.
                'email' => $person->email,
                // Empty, not absent: referees and staff are rows an organizer
                // typed on the personnel screen, never entrants who filled in a
                // form, so there is nothing to read. Spelled out so the key is
                // present for every pool and no reader has to branch on it.
                'values' => [],
            ];
        }

        return $out;
    }

    /**
     * The row for one person's card in one event, minted on first print and
     * reused on every later one.
     *
     * `firstOrCreate` on (event, type, recipient) rather than `create`, and that
     * is the whole idempotency story: reprinting after adding a player is an
     * ordinary thing for an organizer to do, and a second number would kill the
     * QR on every card already handed out. It also means a 400-card reprint
     * mints zero numbers instead of burning 400.
     *
     * The descriptive columns are refreshed on a reprint while the number is
     * not, which is the one place this row is deliberately not frozen: a card
     * being printed *now* should carry the name as it is now. What the snapshot
     * protects is the card already in someone's hand, and nothing reprints that.
     *
     * @param  array{type: string, id: string, name?: string, role_label?: string, team_name?: string, email?: ?string}  $person
     */
    public function issue(Event $event, IdCardTemplate $template, array $person): IdCard
    {
        // Spelled once and used for both the insert and the refresh. Listing
        // them only in the update would insert a row missing `recipient_name`,
        // which is NOT NULL — firstOrCreate writes before anything else runs.
        $descriptive = [
            'id_card_template_id' => $template->id,
            'recipient_name' => (string) ($person['name'] ?? ''),
            'role_label' => $person['role_label'] ?? null,
            'team_name' => $person['team_name'] ?? null,
            'recipient_email' => $person['email'] ?? null,
        ];

        $key = [
            'event_id' => $event->id,
            'recipient_type' => $person['type'],
            'recipient_id' => $person['id'],
        ];

        // Looked up first rather than through firstOrCreate(), because PHP
        // evaluates that method's second argument whether or not it is used:
        // nextNumber() opens a transaction and takes a row lock, so a 400-card
        // reprint would run 400 locked transactions to discard every number it
        // minted. The unique index is still the backstop if two batches race.
        $card = IdCard::where($key)->first();

        if ($card) {
            $card->update($descriptive);

            return $card;
        }

        return IdCard::create([
            ...$key,
            ...$descriptive,
            'organization_id' => $event->organization_id,
            'card_number' => $this->nextNumber(),
            'issued_at' => Carbon::now(),
        ]);
    }

    /**
     * Next card number for the current month, e.g. IDC-2026-11-0001.
     *
     * Lifted from CertificateService::nextNumber() deliberately rather than
     * shared with it: the two sequences must not interleave, and a shared
     * helper parameterised by prefix would be one function away from a card and
     * a certificate racing for the same row lock. Concurrent batches are
     * serialized by locking the month's highest row; the unique index on the
     * column is the backstop.
     *
     * Dashes, not the slashes used for invoices: this number is a URL segment
     * (the QR points at /verify-card/{number}) and a filename inside the zip.
     */
    public function nextNumber(): string
    {
        $prefix = (string) config('id_card.number_prefix', 'IDC');
        $period = Carbon::now()->format('Y-m');

        return DB::transaction(function () use ($prefix, $period) {
            // Postgres rejects FOR UPDATE alongside an aggregate, so take the
            // highest row and lock *that* rather than locking a max().
            // Sequences are zero-padded, so lexical order is numeric order.
            $last = IdCard::where('card_number', 'like', "{$prefix}-{$period}-%")
                ->orderByDesc('card_number')
                ->lockForUpdate()
                ->value('card_number');

            $seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

            return sprintf('%s-%s-%04d', $prefix, $period, $seq);
        });
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
     * `$issued` is optional, and that is not laziness: it carries the number a
     * `qr` field encodes and the one a `card_number` field prints, so a template
     * placing neither renders identically with or without it. Making it required
     * would force every caller — and every render test — to issue a row before
     * it could draw a card that has nothing to verify. A `qr` field with no row
     * simply draws nothing, the same way a text field with no value draws
     * nothing.
     *
     * `$person['values']` is where every key outside the handful spelled below
     * comes from — the row's own columns and the entrant's form answers, both
     * resolved by recipients(). It is merged UNDER the fixed keys rather than
     * over them, so a registration question an organizer happened to key `name`
     * can never displace the name the card is issued to.
     *
     * @param  array{name?: string, role_label?: string, team_name?: string, photo_url?: ?string, values?: array<string, string>}  $person
     */
    public function render(
        IdCardTemplate $template,
        array $person,
        ?ImageInterface $background = null,
        ?IdCard $issued = null,
    ): string {
        $w = $this->px($template->width_mm);
        $h = $this->px($template->height_mm);

        $bg = $background ?? $this->background($template);
        $card = $bg ? clone $bg : $this->manager()->createImage($w, $h)->fill('ffffff');

        $values = [
            ...(is_array($person['values'] ?? null) ? $person['values'] : []),
            'name' => (string) ($person['name'] ?? ''),
            'role_label' => (string) ($person['role_label'] ?? ''),
            'team_name' => (string) ($person['team_name'] ?? ''),
            'event_name' => (string) ($person['event_name'] ?? ''),
            'organization_name' => (string) ($person['organization_name'] ?? ''),
            'event_date' => (string) ($person['event_date'] ?? ''),
            // From the row, not the person array: the number is minted by
            // issue(), and reading it off anything else would be a second
            // spelling of the string the QR beside it already encodes.
            'card_number' => (string) ($issued?->card_number ?? ''),
        ];

        // Array order is z-order: the organizer arranged these, and redrawing
        // them in any other order would rearrange their overlaps.
        foreach ((array) $template->fields as $field) {
            if (($field['key'] ?? '') === 'photo') {
                $this->drawPhoto($card, $field, $person, $w, $h);

                continue;
            }

            if (($field['key'] ?? '') === 'qr') {
                if ($issued !== null) {
                    $this->drawQr($card, $field, $issued->verifyUrl(), $w, $h);
                }

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
     * Each card is issued as a row first, then rendered once and used twice: it
     * goes into the zip *and* is parked under PRINT_PREFIX as its own object.
     * Rendering is the expensive half (GD, minutes for a full batch), so the
     * alternative — rendering again when someone emails the batch — would double
     * it, and slicing a single card back out of a 400-card zip to attach it
     * would mean downloading 400 cards to send one.
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

        // The three values that are the same on every card in a batch, resolved
        // once above the loop. The organization is a relation, and reading it
        // per person would be one lazy load per card.
        $event->loadMissing('organization:id,name');

        $eventValues = [
            'event_name' => $event->name,
            'organization_name' => (string) ($event->organization?->name ?? ''),
            'event_date' => $event->start_date
                ? Carbon::parse($event->start_date)->translatedFormat('d F Y')
                : '',
        ];

        foreach (array_values($recipients) as $i => $person) {
            $person = [...$person, ...$eventValues];

            $issued = $this->issue($event, $template, $person);
            $png = $this->render($template, $person, $background, $issued);

            $this->storePrint($issued, $png);

            // NNN- prefix, not the slug alone: two people really are called
            // "Ahmad Fauzi", and numbering makes a collision impossible without
            // a lookup while matching the order shown on screen. The card number
            // is in there too, so a printed card can be traced back to the file
            // it came out of.
            $name = sprintf(
                '%03d-%s-%s.png',
                $i + 1,
                Str::slug((string) ($person['name'] ?? '')) ?: 'kartu',
                $issued->card_number,
            );

            $zip->addFromString($name, $png);

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

        $this->put($key, (string) file_get_contents($path), 'application/zip');

        @unlink($path);

        return $key;
    }

    /**
     * Park one card's PNG under PRINT_PREFIX and record the key on its row.
     *
     * Keyed on the card id, not the number: a reprint overwrites the same object
     * rather than leaving the old render behind under a key nothing names. The
     * row already guarantees one id per (event, person).
     */
    public function storePrint(IdCard $card, string $png): IdCard
    {
        $key = self::PRINT_PREFIX.'/'.$card->id.'.png';

        $this->put($key, $png, 'image/png');

        $card->update(['png_key' => $key]);

        return $card;
    }

    /**
     * Write an object to whichever disk this service reads from.
     *
     * The same branch UploadController takes, and it is not optional: the `r2`
     * disk is configured `throw => false, report => false`, so without
     * credentials a write through it would no-op *silently* and the download
     * would 404 on an object nothing ever complained about. Spelled once because
     * two writers (the zip and the per-card prints) must not disagree with
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
     * Issued cards bucketed into the mails that will carry them.
     *
     * Grouped by address, which is what makes one manager of a twenty-player
     * squad receive one email instead of twenty. Personnel each hold their own
     * address and so fall out as groups of one without a branch — the pools are
     * already flattened by recipients(), and this reads the column it wrote.
     *
     * Cards with no address are returned separately rather than dropped, and
     * that distinction is the point: a team registered without a manager
     * account is an ordinary state, and "40 kartu dikirim" printed over 55
     * people reads as a bug. The caller reports the gap before anything is
     * queued.
     *
     * @param  Collection<int, IdCard>  $cards
     * @return array{groups: array<string, array<int, string>>, unsendable: int}
     */
    public function groupForSending(Collection $cards): array
    {
        $sendable = $cards->filter(fn (IdCard $card) => (bool) $card->recipient_email && (bool) $card->png_key);

        return [
            'groups' => $sendable
                ->groupBy('recipient_email')
                ->map(fn (Collection $group) => $group->pluck('id')->all())
                ->all(),
            'unsendable' => $cards->count() - $sendable->count(),
        ];
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
        $disk = Storage::disk(self::diskName());

        return $disk;
    }

    /**
     * The disk name, for the one caller that needs the name rather than the
     * adapter: `Attachment::fromStorageDisk()` in IdCardsIssuedMail takes a
     * string. Static because a Mailable is serialized onto the queue and must
     * not carry a service with it.
     *
     * Spelled here, not there — three readers of "R2 or local?" (this, put(),
     * and the mail attachment) that disagree produce a mail with no attachment,
     * which sends successfully and stamps `sent_at`.
     */
    public static function diskName(): string
    {
        return config('r2.key') ? 'r2' : 'public';
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
                // TOP, not the default CENTER: these are headshots, and a
                // centred crop on a photo taller than its box takes its slice
                // out of the middle of the frame — where the chin is, not the
                // head. Trimming the bottom loses shoulders; trimming the top
                // loses the face, and a card exists to be matched to one.
                // Horizontally it still centres, which is what a portrait
                // dropped into a wide box wants. Same reasoning as
                // PdfImageService::dataUri()'s `photo` shape.
                $photo = ($field['fit'] ?? 'cover') === 'contain'
                    ? $photo->contain($boxW, $boxH)
                    : $photo->cover($boxW, $boxH, Alignment::TOP);
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
     * The verification QR, drawn module by module straight onto the card.
     *
     * NOT QrImage::svgDataUri(): that exists for dompdf, which draws SVG through
     * php-svg-lib, and GD cannot draw SVG at all. NOT BaconQrCode's
     * ImagickImageBackEnd either — imagick is installed in api/Dockerfile for
     * the one thing GD cannot do (encode .ico), but this whole renderer is GD
     * and a local PHP without imagick would fail every card test rather than
     * one. Walking the matrix needs neither backend.
     *
     * Two details decide whether the printed code actually scans:
     *
     *   Integer module size. `floor(side / modules)`, never the float: a module
     *     7.4px wide gets drawn alternately 7 and 8 px, which walks the finder
     *     patterns out of square. The leftover pixels become margin instead.
     *   An explicit quiet zone. QrImage passes margin 0 and says why — every
     *     sheet using it draws its own padding. A card background does not, and
     *     a QR butted against artwork does not scan, so four modules of white
     *     are drawn here. This is the one place that reasoning inverts.
     *
     * The box is squared off its shorter side rather than stretched: `w != h`
     * leaves slack, because a non-square QR is an unreadable one.
     *
     * @param  array<string, mixed>  $field
     */
    protected function drawQr(ImageInterface $card, array $field, string $url, int $w, int $h): void
    {
        $boxW = max(1, (int) round(((float) ($field['w'] ?? 0) / 100) * $w));
        $boxH = max(1, (int) round(((float) ($field['h'] ?? 0) / 100) * $h));
        $x = (int) round(((float) ($field['x'] ?? 0) / 100) * $w);
        $y = (int) round(((float) ($field['y'] ?? 0) / 100) * $h);

        try {
            // ISO-8859-1 rather than UTF-8: a verification URL is ASCII, and the
            // UTF-8 path prefixes an ECI segment that some scanners refuse (the
            // warning is in Encoder::encode() itself).
            $matrix = Encoder::encode($url, ErrorCorrectionLevel::M(), 'ISO-8859-1')->getMatrix();
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

        $card->insert($plate, $originX, $originY);
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
                /*
                 * Vertical align must be stated: Font defaults to BOTTOM, and
                 * every line of the layout would ride up by its own height.
                 *
                 * `y` anchors the whole wrapped BLOCK, not the first line, so
                 * which edge it anchors is what decides where a long name
                 * goes. Under the old hardcoded 'top', "ADIB" sat high in its
                 * plate while "AHMAD FAUZI RAHMATULLAH" wrapped to two lines
                 * and grew downwards out of it — measured: the text's midpoint
                 * moved 25px for a four-line name while 'center' held it still.
                 *
                 * 'top' remains the default so every template written before
                 * this field existed renders exactly as it did; the editor
                 * starts new text fields at 'middle', which is what a name
                 * plate wants.
                 *
                 * Translated, not passed through: Alignment::from() only knows
                 * the enum's own values and there is no 'middle' in it — the
                 * same trap already documented on placeholder()'s 'center'.
                 */
                $f->align($align, match ($field['valign'] ?? 'top') {
                    'middle' => 'center',
                    'bottom' => 'bottom',
                    default => 'top',
                });
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
