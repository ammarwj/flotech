<?php

namespace App\Jobs;

use App\Services\MediaCleanupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delete storage objects whose rows are already gone.
 *
 * Queued rather than inline because a slow or unreachable bucket must not fail
 * or hang the delete request the user is waiting on — the row is committed by
 * then, and removing a file is idempotent, so a retry is always safe.
 *
 * Carries plain keys/URLs, not a model: by the time it runs there is nothing
 * left to load.
 */
class PurgeMediaJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string|null>  $urls
     */
    public function __construct(public array $urls) {}

    public function handle(MediaCleanupService $media): void
    {
        $media->purge($this->urls);
    }
}
