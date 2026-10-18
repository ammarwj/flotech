<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Event;
use App\Services\CertificateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Packs one batch of already-issued certificate PDFs into a zip on R2.
 *
 * Queued even for a single document, and that is not a style choice — the same
 * reasoning as GenerateIdCardsJob, written out there. Production serves the API
 * with a single-process `php artisan serve` behind an nginx that never sets
 * `proxy_read_timeout` (so: 60s), and 500 objects pulled out of the bucket one
 * at a time is minutes of round trips. A synchronous route would blow the
 * timeout *and* block every other request — logins, Midtrans webhooks, ticket
 * scans — while it ran.
 *
 * Nothing here renders: the PDFs were written when the certificates were
 * issued, so a failed batch costs a retry, never a document.
 */
class GenerateCertificateZipJob implements ShouldQueue
{
    use Queueable;

    /** Beats the supervisor's `timeout => 60`: this job is minutes of bucket I/O. */
    public int $timeout = 900;

    /**
     * A half-written zip must not be retried blind — the second attempt would
     * overwrite an object a download may already be streaming. A failed batch is
     * reported and asked for again by hand.
     */
    public int $tries = 1;

    /** How long a finished batch stays downloadable. `certificates:prune-zips` matches it. */
    public const TTL_HOURS = 24;

    public function __construct(
        public string $batchId,
        /** @var array<int, string> */
        public array $certificateIds,
        public ?string $eventId,
    ) {}

    public static function cacheKey(string $batchId): string
    {
        return "certificate-zips:batch:{$batchId}";
    }

    public function handle(CertificateService $certificates): void
    {
        $this->mark(['status' => 'running']);

        try {
            // Re-read rather than carrying the models through the payload: the
            // ids were resolved against the organization's own pool in the
            // controller, and `whereIn` keeps that narrowing intact.
            $rows = Certificate::whereIn('id', $this->certificateIds)
                ->orderBy('certificate_number')
                ->get();

            $key = $certificates->zip(
                $rows,
                // Progress every document rather than every N: the poller is the
                // only thing reading it, and a bar that jumps in 25s on a
                // 30-document batch looks stuck twice and finished once.
                fn (int $done) => $this->mark(['done' => $done]),
            );

            $this->mark([
                'status' => 'done',
                'done' => $rows->count(),
                'key' => $key,
                'filename' => $certificates->zipFilename(
                    $this->eventId ? Event::find($this->eventId) : null,
                ),
            ]);
        } catch (Throwable $e) {
            report($e);

            // The message is ours, not the exception's: a stack trace or an S3
            // error string on an organizer's screen tells them nothing they can
            // act on. report() keeps the real one.
            $this->mark(['status' => 'failed', 'error' => 'Zip sertifikat gagal dibuat. Coba lagi.']);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        // handle() already wrote 'failed' for anything it could catch. This
        // covers what it cannot: a timeout kill, or the worker being restarted
        // mid-batch, which would otherwise leave the poller spinning on
        // 'running' until the entry expired a day later.
        $this->mark(['status' => 'failed', 'error' => 'Zip sertifikat gagal dibuat. Coba lagi.']);
    }

    /**
     * Merge into the batch entry.
     *
     * Read-modify-write rather than separate keys because the poller wants one
     * shape in one read. Two workers never touch one batch (`tries = 1`, one
     * dispatch per batch id), so there is nothing here to race with.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function mark(array $changes): void
    {
        $key = self::cacheKey($this->batchId);
        $current = (array) Cache::get($key, []);

        Cache::put($key, array_merge($current, $changes), now()->addHours(self::TTL_HOURS));
    }
}
