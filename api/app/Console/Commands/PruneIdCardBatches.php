<?php

namespace App\Console\Commands;

use App\Jobs\GenerateIdCardsJob;
use App\Services\IdCardService;
use Illuminate\Console\Command;

/**
 * Delete the zips left behind by finished ID card batches.
 *
 * Every other generated file in this system is reachable from a row, so
 * MediaCleanupService can find it by walking the tables that are about to be
 * deleted. A batch zip has no row at all — the batch lives in the cache for a
 * day and then is simply gone — which means nothing ever names its key again
 * and no existing sweep will ever see it. Without this command the bucket grows
 * by one zip per generate, forever.
 *
 * The cutoff is `GenerateIdCardsJob::TTL_HOURS`, not a number of its own: the
 * cache entry the download endpoint reads expires on exactly that clock, so an
 * object older than it is already undownloadable. Reading the constant keeps
 * the two from drifting apart into either dead objects or dead links.
 */
class PruneIdCardBatches extends Command
{
    protected $signature = 'id-cards:prune {--hours=}';

    protected $description = 'Hapus zip ID card yang sudah lewat masa unduh (batch-nya sendiri sudah kedaluwarsa di cache).';

    public function handle(IdCardService $cards): int
    {
        $hours = max((int) ($this->option('hours') ?: GenerateIdCardsJob::TTL_HOURS), 1);
        $cutoff = now()->subHours($hours)->getTimestamp();

        // Through the service, not Storage::disk('r2'): it is the one place that
        // decides whether a batch lives on R2 or on the local `public` disk, and
        // a second reader of that rule would eventually sweep the wrong bucket.
        $disk = $cards->storage();
        $deleted = 0;

        foreach ($disk->files(IdCardService::BATCH_PREFIX) as $key) {
            // lastModified() is a remote call per object on S3, so it is asked
            // only about files that are candidates by name. Belt and braces
            // anyway: zip() is the only writer under this prefix, and this loop
            // ends in a delete.
            if (! str_ends_with($key, '.zip')) {
                continue;
            }

            if ($disk->lastModified($key) >= $cutoff) {
                continue;
            }

            $disk->delete($key);
            $deleted++;
        }

        $this->info("{$deleted} zip ID card lebih tua dari {$hours} jam dihapus.");

        return self::SUCCESS;
    }
}
