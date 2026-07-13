<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Event;
use App\Models\Player;
use App\Models\Team;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Issues and renders certificates.
 *
 * The only thing allowed to mint a certificate number or write a PDF. Numbers
 * come from the same locked-sequence scheme as invoices (see SubscriptionService)
 * because a certificate is a document too: it is quoted, verified and must never
 * collide.
 */
class CertificateService
{
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
            'qr' => $this->qrDataUri($certificate->verifyUrl()),
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

    /** QR as an inline SVG — dompdf draws it through php-svg-lib, no raster needed. */
    protected function qrDataUri(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(300, 0), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url));
    }
}
