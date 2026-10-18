<?php

namespace App\Console\Commands;

use App\Jobs\GenerateCertificateZipJob;
use App\Services\CertificateService;
use Illuminate\Console\Command;

/**
 * Delete the zips left behind by bulk certificate downloads.
 *
 * Twin of `id-cards:prune`, whose docblock spells out why a sweep is the only
 * way these can ever be collected: a batch zip is the one generated file no row
 * points at — the batch lives in the cache for a day and is then simply gone —
 * so MediaCleanupService will never see it and the bucket grows by one zip per
 * click, forever.
 *
 * The cutoff reads GenerateCertificateZipJob::TTL_HOURS rather than carrying a
 * number of its own, so the objects and the cache entries that name them cannot
 * drift into either dead files or dead links.
 */
class PruneCertificateZips extends Command
{
    protected $signature = 'certificates:prune-zips {--hours=}';

    protected $description = 'Hapus zip unduhan kolektif sertifikat yang sudah lewat masa unduh.';

    public function handle(CertificateService $certificates): int
    {
        $hours = max((int) ($this->option('hours') ?: GenerateCertificateZipJob::TTL_HOURS), 1);
        $cutoff = now()->subHours($hours)->getTimestamp();

        // Through the service, not Storage::disk('r2'): it is the one place that
        // decides where a batch zip lives, and a second reader of that rule
        // would eventually sweep the wrong disk.
        $disk = $certificates->storage();
        $deleted = 0;

        foreach ($disk->files(CertificateService::BATCH_PREFIX) as $key) {
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

        $this->info("{$deleted} zip sertifikat lebih tua dari {$hours} jam dihapus.");

        return self::SUCCESS;
    }
}
