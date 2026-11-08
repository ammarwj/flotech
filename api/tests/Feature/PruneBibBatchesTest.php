<?php

namespace Tests\Feature;

use App\Services\BibService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sweep that keeps e-BIB batch zips from accumulating forever.
 *
 * Every case compares what goes with what stays. A prune that deletes the whole
 * bucket satisfies "the expired zip is gone" — the files that must survive are
 * what make either assertion mean anything.
 */
class PruneBibBatchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No R2 credentials under test, so BibService::storage() resolves to the
        // `public` disk and the command sweeps that.
        Storage::fake('public');
    }

    /** Write a file and backdate it, since the fake disk stamps everything now. */
    private function fileAged(string $key, int $hoursOld): void
    {
        Storage::disk('public')->put($key, 'bytes');

        touch(Storage::disk('public')->path($key), now()->subHours($hoursOld)->getTimestamp());
    }

    public function test_it_deletes_expired_zips_and_keeps_the_ones_still_downloadable(): void
    {
        $prefix = BibService::BATCH_PREFIX;

        $this->fileAged("{$prefix}/expired.zip", 30);
        $this->fileAged("{$prefix}/fresh.zip", 2);

        $this->artisan('bibs:prune')->assertSuccessful();

        // 30 hours is past GenerateBibsJob::TTL_HOURS, so the batch entry naming
        // that key is already gone from the cache and nothing can ask for the
        // object again. The 2-hour one is still behind a live download button —
        // deleting it would 404 an organizer mid-batch.
        Storage::disk('public')->assertMissing("{$prefix}/expired.zip");
        Storage::disk('public')->assertExists("{$prefix}/fresh.zip");
    }

    /**
     * Invariant: the sweep reaches BATCH_PREFIX and nothing else.
     *
     * Both neighbours are checked in one test, because each is permanent for a
     * different reason and an over-broad sweep would take whichever it reached
     * first:
     *
     *   PRINT_PREFIX — `race_entries.bib_png_key` names each object, and the
     *     runner downloads it from their own proof page. Deleting one takes away
     *     the sheet somebody is carrying to the start line.
     *   `bibs/` — the artwork organizers upload (template-form.tsx passes
     *     folder="bibs"), pointed at by a template row for as long as it lives.
     *     Ageing them out would blank every design older than a day.
     */
    public function test_it_never_touches_printed_sheets_or_template_backgrounds(): void
    {
        $batch = BibService::BATCH_PREFIX;
        $print = BibService::PRINT_PREFIX;

        $this->fileAged("{$print}/some-entry-id.png", 500);
        $this->fileAged('bibs/background.webp', 500);
        $this->fileAged("{$batch}/expired.zip", 500);

        $this->artisan('bibs:prune')->assertSuccessful();

        Storage::disk('public')->assertExists("{$print}/some-entry-id.png");
        Storage::disk('public')->assertExists('bibs/background.webp');
        Storage::disk('public')->assertMissing("{$batch}/expired.zip");
    }

    /**
     * And it does not reach the ID card feature's zips either.
     *
     * The two prefixes differ by one word and both end in `-batches`, so a
     * sweep written against the wrong constant would pass every assertion above
     * while quietly eating another feature's downloads.
     */
    public function test_it_does_not_sweep_the_id_card_prefix(): void
    {
        $this->fileAged('id-card-batches/expired.zip', 500);
        $this->fileAged(BibService::BATCH_PREFIX.'/expired.zip', 500);

        $this->artisan('bibs:prune')->assertSuccessful();

        Storage::disk('public')->assertExists('id-card-batches/expired.zip');
        Storage::disk('public')->assertMissing(BibService::BATCH_PREFIX.'/expired.zip');
    }
}
