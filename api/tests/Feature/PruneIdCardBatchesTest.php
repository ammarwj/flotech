<?php

namespace Tests\Feature;

use App\Services\IdCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sweep that keeps batch zips from accumulating forever.
 *
 * Both cases compare what goes with what stays. A prune that deletes the whole
 * prefix satisfies "the expired zip is gone" — the file that must survive is
 * what makes either assertion mean anything.
 */
class PruneIdCardBatchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No R2 credentials under test, so IdCardService::storage() resolves to
        // the `public` disk and the command sweeps that.
        Storage::fake('public');
    }

    /** Write a file and backdate it, since the fake disk stamps everything now. */
    private function zipAged(string $key, int $hoursOld): void
    {
        Storage::disk('public')->put($key, 'zip-bytes');

        touch(Storage::disk('public')->path($key), now()->subHours($hoursOld)->getTimestamp());
    }

    public function test_it_deletes_expired_zips_and_keeps_the_ones_still_downloadable(): void
    {
        $prefix = IdCardService::BATCH_PREFIX;

        $this->zipAged("{$prefix}/expired.zip", 30);
        $this->zipAged("{$prefix}/fresh.zip", 2);

        $this->artisan('id-cards:prune')->assertSuccessful();

        // 30 hours is past GenerateIdCardsJob::TTL_HOURS, so the batch entry
        // naming that key is already gone from the cache and nothing can ask for
        // the object again. The 2-hour one is still behind a live download
        // button — deleting it would 404 an organizer mid-batch.
        Storage::disk('public')->assertMissing("{$prefix}/expired.zip");
        Storage::disk('public')->assertExists("{$prefix}/fresh.zip");
    }

    public function test_it_never_touches_the_template_backgrounds(): void
    {
        $prefix = IdCardService::BATCH_PREFIX;

        // The card designs organizers upload land under `id-cards/`
        // (template-form.tsx passes folder="id-cards"), and they are permanent:
        // a template row points at one for as long as the row lives. Ageing them
        // out would blank every card design older than a day.
        $this->zipAged('id-cards/background.webp', 500);
        $this->zipAged("{$prefix}/expired.zip", 500);

        $this->artisan('id-cards:prune')->assertSuccessful();

        Storage::disk('public')->assertExists('id-cards/background.webp');
        Storage::disk('public')->assertMissing("{$prefix}/expired.zip");
    }
}
