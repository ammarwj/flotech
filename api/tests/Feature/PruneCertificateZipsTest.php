<?php

namespace Tests\Feature;

use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sweep that keeps bulk-download zips from accumulating forever.
 *
 * Both cases compare what goes with what stays. A prune that deletes the whole
 * bucket satisfies "the expired zip is gone" — the files that must survive are
 * what make either assertion mean anything, and here one of them is the
 * certificate PDFs themselves.
 */
class PruneCertificateZipsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    /** Write a file and backdate it, since the fake disk stamps everything now. */
    private function aged(string $key, int $hoursOld): void
    {
        Storage::disk('r2')->put($key, 'bytes');

        touch(Storage::disk('r2')->path($key), now()->subHours($hoursOld)->getTimestamp());
    }

    public function test_it_deletes_expired_zips_and_keeps_the_ones_still_downloadable(): void
    {
        $prefix = CertificateService::BATCH_PREFIX;

        $this->aged("{$prefix}/expired.zip", 30);
        $this->aged("{$prefix}/fresh.zip", 2);

        $this->artisan('certificates:prune-zips')->assertSuccessful();

        // 30 hours is past GenerateCertificateZipJob::TTL_HOURS, so the batch
        // entry naming that key is already gone from the cache and nothing can
        // ask for the object again. The 2-hour one is still behind a live
        // download button — deleting it would 404 an organizer mid-batch.
        Storage::disk('r2')->assertMissing("{$prefix}/expired.zip");
        Storage::disk('r2')->assertExists("{$prefix}/fresh.zip");
    }

    public function test_it_never_touches_the_certificate_pdfs(): void
    {
        $prefix = CertificateService::BATCH_PREFIX;

        // The issued documents live under `certificates/` and are permanent:
        // `certificates.pdf_key` points at them, the QR printed on each one
        // opens a verification page that must keep working forever, and
        // CertificateController::download() is deliberately ungated for exactly
        // that reason. A sweep that walked the wrong prefix would quietly
        // destroy every certificate the platform ever issued.
        $this->aged('certificates/old-document.pdf', 500);
        $this->aged("{$prefix}/expired.zip", 30);

        $this->artisan('certificates:prune-zips')->assertSuccessful();

        Storage::disk('r2')->assertExists('certificates/old-document.pdf');
        Storage::disk('r2')->assertMissing("{$prefix}/expired.zip");
    }
}
