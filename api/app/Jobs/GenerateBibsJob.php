<?php

namespace App\Jobs;

use App\Models\BibTemplate;
use App\Models\Event;
use App\Services\BibService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Renders one batch of e-BIBs into a zip on R2.
 *
 * Queued even for a single sheet, and that is not a style choice — the reason
 * GenerateIdCardsJob gives applies more strongly here, because an A5 sheet at
 * 300 DPI is 2480x1748 against a card's 1011x638. Production serves the API
 * with a single-process `php artisan serve` behind an nginx that never sets
 * `proxy_read_timeout` (so: 60s), and 500 sheets of GD work is minutes. A
 * synchronous route would blow the timeout *and* block every other request —
 * logins, Midtrans webhooks, race check-ins — while it ran. One code path, one
 * failure mode, no guessed threshold for "small enough".
 */
class GenerateBibsJob implements ShouldQueue
{
    use Queueable;

    /** Beats the supervisor's `timeout => 60`: this job is minutes of GD work. */
    public int $timeout = 900;

    /**
     * A half-written zip must not be retried blind — the second attempt would
     * re-render every sheet and overwrite an object a download may already be
     * streaming. A failed batch is reported and generated again by hand.
     */
    public int $tries = 1;

    /** How long a finished batch stays downloadable. `bibs:prune` matches it. */
    public const TTL_HOURS = 24;

    public function __construct(
        public string $batchId,
        public string $eventId,
        public string $templateId,
        /** @var array<int, array<string, mixed>> */
        public array $recipients,
    ) {}

    public static function cacheKey(string $batchId): string
    {
        return "bibs:batch:{$batchId}";
    }

    public function handle(BibService $bibs): void
    {
        $this->mark(['status' => 'running']);

        try {
            $event = Event::findOrFail($this->eventId);
            $template = BibTemplate::findOrFail($this->templateId);

            $key = $bibs->zip(
                $event,
                $template,
                $this->recipients,
                // Progress is written every sheet rather than every N: the
                // poller is the only thing reading it, and a bar that jumps in
                // 25s on a 30-runner batch looks stuck twice and finished once.
                fn (int $done) => $this->mark(['done' => $done]),
            );

            $this->mark([
                'status' => 'done',
                'done' => count($this->recipients),
                'key' => $key,
                'filename' => $bibs->zipFilename($event),
            ]);
        } catch (Throwable $e) {
            report($e);

            // The message is ours, not the exception's: a stack trace or an S3
            // error string on an organizer's screen tells them nothing they can
            // act on. report() keeps the real one.
            $this->mark(['status' => 'failed', 'error' => 'e-BIB gagal dibuat. Coba lagi.']);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        // handle() already wrote 'failed' for anything it could catch. This
        // covers what it cannot: a timeout kill, or the worker being restarted
        // mid-batch, which would otherwise leave the poller spinning on
        // 'running' until the entry expired a day later.
        $this->mark(['status' => 'failed', 'error' => 'e-BIB gagal dibuat. Coba lagi.']);
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
