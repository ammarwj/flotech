<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Event;
use App\Models\Player;
use App\Models\Team;
use App\Support\QrImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Issues and renders certificates.
 *
 * The only thing allowed to mint a certificate number or write a PDF. Numbers
 * come from the same locked-sequence scheme as invoices (see EventPlanOrderService)
 * because a certificate is a document too: it is quoted, verified and must never
 * collide.
 */
class CertificateService
{
    /**
     * Where a bulk-download zip lives.
     *
     * Deliberately *not* `certificates/`: that prefix holds the permanent PDFs
     * `pdf_key` points at, and `certificates:prune-zips` deletes everything old
     * under this one. Same reasoning as IdCardService::BATCH_PREFIX, written out
     * there — an extension check alone is a one-typo-deep guard in front of a
     * delete loop, and here that loop would eat the documents themselves.
     */
    public const BATCH_PREFIX = 'certificate-batches';

    public function __construct(protected R2StorageService $r2) {}

    /**
     * Everyone in an event who could receive a certificate: the approved teams,
     * and every active player in them.
     *
     * @return array{teams: Collection<int, Team>, players: Collection<int, Player>}
     */
    public function recipients(Event $event): array
    {
        $teams = $event->teams()
            ->where('status', 'approved')
            ->with(['manager:id,email', 'players' => fn ($q) => $q->where('is_active', true)->orderBy('full_name')])
            ->orderBy('name')
            ->get();

        return [
            'teams' => $teams,
            'players' => $teams->flatMap(fn (Team $team) => $team->players)->values(),
        ];
    }

    /**
     * Issue one certificate per recipient and render each PDF.
     *
     * Recipients already holding this award for this event are skipped rather
     * than failing the batch — re-running after adding a team is a normal thing
     * for an organizer to do, and the unique index would otherwise blow up.
     *
     * @param  array<int, array{type: string, id: string}>  $recipients
     * @return Collection<int, Certificate>
     */
    public function issueBatch(
        Event $event,
        CertificateTemplate $template,
        array $recipients,
        string $award,
    ): Collection {
        $pool = $this->recipients($event);
        $teams = $pool['teams']->keyBy('id');
        $players = $pool['players']->keyBy('id');

        $existing = Certificate::where('event_id', $event->id)
            ->where('award_title', $award)
            ->pluck('recipient_id')
            ->all();

        // The background is the same for every row — fetch it once, not N times.
        $background = $this->backgroundDataUri($template->background_url);

        $issued = collect();

        foreach ($recipients as $recipient) {
            $id = $recipient['id'];

            if (in_array($id, $existing, true)) {
                continue;
            }

            $subject = $recipient['type'] === 'player' ? $players->get($id) : $teams->get($id);

            if (! $subject) {
                continue; // not an approved team / active player of this event
            }

            $team = $recipient['type'] === 'player' ? $teams->get($subject->team_id) : $subject;

            $certificate = Certificate::create([
                'organization_id' => $event->organization_id,
                'event_id' => $event->id,
                'certificate_template_id' => $template->id,
                'certificate_number' => $this->nextNumber(),
                'recipient_type' => $recipient['type'],
                'recipient_id' => $id,
                'recipient_name' => $recipient['type'] === 'player' ? $subject->full_name : $subject->name,
                'team_name' => $recipient['type'] === 'player' ? $team?->name : null,
                // Neither teams nor players carry an email — the team's manager
                // account is the only address we have, so that's who receives it.
                'recipient_email' => $team?->manager?->email,
                'award_title' => $award,
                'issued_at' => Carbon::now(),
            ]);

            // The event is already in hand — hand it to the resource rather than
            // letting it re-query once per issued row.
            $certificate->setRelation('event', $event);

            $this->store($certificate, $template, $background);

            $issued->push($certificate);
        }

        return $issued;
    }

    /** Render the PDF and park it in R2, recording the key on the certificate. */
    public function store(Certificate $certificate, CertificateTemplate $template, ?string $background = null): Certificate
    {
        $pdf = $this->render($certificate, $template, $background);
        $key = 'certificates/'.$certificate->id.'.pdf';

        $this->r2->put($key, $pdf, 'application/pdf');

        $certificate->update(['pdf_key' => $key]);

        return $certificate;
    }

    /** The PDF bytes for one certificate. */
    public function render(Certificate $certificate, CertificateTemplate $template, ?string $background = null): string
    {
        $certificate->loadMissing('event.organization');

        return Pdf::loadView('pdf.certificate', [
            'certificate' => $certificate,
            'template' => $template,
            'background' => $background ?? $this->backgroundDataUri($template->background_url),
            'values' => $this->values($certificate),
            'qr' => QrImage::svgDataUri($certificate->verifyUrl()),
        ])
            ->setPaper('a4', $template->orientation === 'portrait' ? 'portrait' : 'landscape')
            ->output();
    }

    /**
     * What each placeholder prints. Keys mirror config('certificate.fields').
     *
     * @return array<string, string>
     */
    public function values(Certificate $certificate): array
    {
        $event = $certificate->event;

        return [
            'recipient_name' => $certificate->recipient_name,
            'team_name' => $certificate->team_name ?? '',
            'award_title' => $certificate->award_title,
            'event_name' => $event?->name ?? '',
            'event_date' => $event?->start_date
                ? Carbon::parse($event->start_date)->translatedFormat('d F Y')
                : '',
            'organization_name' => $event?->organization?->name ?? '',
            'certificate_number' => $certificate->certificate_number,
        ];
    }

    /**
     * Next certificate number for the current month, e.g. CERT-2026-07-0001.
     * Concurrent batches are serialized by locking the month's rows; the unique
     * index on the column is the backstop.
     *
     * Separated by dashes, not the slashes used for invoices: this number is a
     * URL segment (the QR points at /verify/{number}) and a PDF filename.
     */
    public function nextNumber(): string
    {
        $prefix = config('certificate.number_prefix', 'CERT');
        $period = Carbon::now()->format('Y-m');

        return DB::transaction(function () use ($prefix, $period) {
            // Postgres rejects FOR UPDATE alongside an aggregate, so take the
            // highest row and lock *that* rather than locking a max().
            // Sequences are zero-padded, so lexical order is numeric order.
            $last = Certificate::where('certificate_number', 'like', "{$prefix}-{$period}-%")
                ->orderByDesc('certificate_number')
                ->lockForUpdate()
                ->value('certificate_number');

            $seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

            return sprintf('%s-%s-%04d', $prefix, $period, $seq);
        });
    }

    /**
     * Pack already-rendered PDFs into one zip on the bucket, returning its key.
     *
     * Nothing is rendered here — `store()` wrote every one of these objects when
     * the certificate was issued, so this is a copy, not a re-run. A row whose
     * object has gone missing is skipped rather than failing the batch, the same
     * way issueBatch() skips a recipient who already holds the award: one
     * unreadable row must not cost an organizer the other two hundred.
     *
     * Written to the bucket rather than local disk because docker-compose gives
     * `worker` no volume shared with `api` — a zip on the worker's disk is
     * invisible to the container that serves the download.
     *
     * @param  Collection<int, Certificate>  $certificates
     * @param  ?callable(int): void  $onProgress  called with the running count
     */
    public function zip(Collection $certificates, ?callable $onProgress = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'certzip').'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $disk = $this->storage();

        // Counts entries actually written, NOT the loop position, and the
        // difference is load-bearing twice over.
        //
        // `close()` on an archive holding nothing writes no file at all: the
        // reopen then returns 9 (ZipArchive::ER_NOENT) and every later call on
        // the object throws "Invalid or uninitialized Zip object" — including
        // `addFromString`, so one flush at the wrong moment kills the whole
        // batch. Flushing on `$i` reaches that state whenever the rows crossing
        // a boundary had no readable object between them, which is exactly the
        // skip path above. It also flushed for rows that added no memory to
        // flush.
        $written = 0;

        foreach ($certificates->values() as $i => $certificate) {
            // The certificate number is already unique and already the filename
            // download() hands over, so there is no NNN- prefix to add here:
            // unlike ID cards, where two people really are called the same
            // thing, two certificates cannot share a name.
            $name = str_replace('/', '-', (string) $certificate->certificate_number).'.pdf';

            try {
                $bytes = $certificate->pdf_key ? $disk->get($certificate->pdf_key) : null;
            } catch (Throwable $e) {
                report($e);
                $bytes = null;
            }

            if ($bytes !== null) {
                $zip->addFromString($name, $bytes);
                $written++;

                // addFromString holds its payload in memory until the archive is
                // closed, so a 500-document batch would carry every PDF at once.
                if ($written % 25 === 0) {
                    $zip->close();
                    $zip->open($path);
                }
            }

            if ($onProgress) {
                $onProgress($i + 1);
            }
        }

        $zip->close();

        // Every row skipped, so nothing was ever added and the close above wrote
        // no file: `file_get_contents()` on it would warn and the batch would
        // land on the bucket as an empty object that downloads as a broken zip.
        // The controller already refuses a filter with no PDFs, so reaching here
        // means the objects went missing between the request and the worker —
        // rare, but the failure has to be the job's, not a corrupt download.
        if ($written === 0) {
            @unlink($path);

            throw new RuntimeException('Tidak ada berkas sertifikat yang bisa dibaca dari penyimpanan.');
        }

        $key = self::BATCH_PREFIX.'/'.Str::uuid()->toString().'.zip';

        $disk->put($key, (string) file_get_contents($path));

        @unlink($path);

        return $key;
    }

    /**
     * The disk a batch zip lives on.
     *
     * Read through here by zip(), the download endpoint *and* the prune command,
     * so none of the three can disagree about where the file is — the drift
     * there is a 404 on an object that exists, or a sweep of the wrong bucket.
     *
     * Note this is Storage, not R2StorageService::put(): that one writes through
     * its own S3Client and so cannot be faked, which would leave this whole
     * feature untestable end to end. Reading is already done this way — see
     * download() and fetchBytes().
     */
    public function storage(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('r2');

        return $disk;
    }

    /** The filename the browser saves a batch under. */
    public function zipFilename(?Event $event): string
    {
        $scope = $event ? ($event->slug ?: Str::slug($event->name)) : 'semua-event';

        return 'sertifikat-'.$scope.'-'.now()->format('Ymd-His').'.zip';
    }

    /**
     * The artwork, inlined as JPEG.
     *
     * Two things force the re-encode: uploads land in the bucket as WebP (see
     * UploadController@image) and dompdf cannot draw WebP, and inlining spares
     * dompdf a remote fetch per certificate.
     */
    protected function backgroundDataUri(string $url): ?string
    {
        $bytes = $this->fetchBytes($url);

        if ($bytes === null) {
            return null;
        }

        $jpeg = (string) (new ImageManager(new GdDriver))
            ->decodeBinary($bytes)
            ->scaleDown(width: 2400, height: 2400)
            ->encode(new JpegEncoder(quality: 88));

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    /**
     * Bytes behind a stored image URL. Our own uploads are read straight off the
     * bucket — going back out through the public r2.dev host would be a needless
     * round trip, and the container can't verify its certificate anyway.
     */
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
