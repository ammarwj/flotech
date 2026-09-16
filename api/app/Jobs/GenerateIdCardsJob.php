<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\IdCardTemplate;
use App\Services\IdCardService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Renders one batch of ID cards into a zip on R2.
 *
 * Queued even for a single card, and that is not a style choice. Production
 * serves the API with a single-process `php artisan serve` behind an nginx that
 * never sets `proxy_read_timeout` (so: 60s), and 500 cards at 300 DPI is
 * minutes of GD work. A synchronous route would blow the timeout *and* block
 * every other request — logins, Midtrans webhooks, ticket scans — while it ran.
 * One code path, one failure mode, no guessed threshold for "small enough".
 */
class GenerateIdCardsJob implements ShouldQueue
{
    use Queueable;

    /** Beats the supervisor's `timeout => 60`: this job is minutes of GD work. */
    public int $timeout = 900;

    /**
     * A half-written zip must not be retried blind — the second attempt would
     * re-render every card and overwrite an object a download may already be
     * streaming. A failed batch is reported and generated again by hand.
     */
    public int $tries = 1;

    /** How long a finished batch stays downloadable. `id-cards:prune` matches it. */
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
        return "id-cards:batch:{$batchId}";
    }

    public function handle(IdCardService $cards): void
    {
        $this->mark(['status' => 'running']);

        try {
            $event = Event::findOrFail($this->eventId);
            $template = IdCardTemplate::findOrFail($this->templateId);

            $key = $cards->zip(
                $event,
                $template,
                $this->recipients,
                // Progress is written every card rather than every N: the poller
                // is the only thing reading it, and a bar that jumps in 25s on a
                // 30-card batch looks stuck twice and finished once.
                fn (int $done) => $this->mark(['done' => $done]),
            );

            $this->mark([
                'status' => 'done',
                'done' => count($this->recipients),
                'key' => $key,
                'filename' => $cards->zipFilename($event),
            ]);
        } catch (Throwable $e) {
            report($e);

            // The message is ours, not the exception's: a stack trace or an S3
            // error string on an organizer's screen tells them nothing they can
            // act on. report() keeps the real one.
            $this->mark(['status' => 'failed', 'error' => 'Kartu gagal dibuat. Coba lagi.']);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        // handle() already wrote 'failed' for anything it could catch. This
        // covers what it cannot: a timeout kill, or the worker being restarted
        // mid-batch, which would otherwise leave the poller spinning on
        // 'running' until the entry expired a day later.
        $this->mark(['status' => 'failed', 'error' => 'Kartu gagal dibuat. Coba lagi.']);
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
