<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificateZipJob;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;
use ZipArchive;

/**
 * Bulk download: what lands in the zip, what does not, and who may read it.
 *
 * `QUEUE_CONNECTION=sync` in phpunit.xml means GenerateCertificateZipJob runs
 * inside the POST, so the batch entry is already final when the request returns
 * and the download can be asked for in the same test — the same note as
 * IdCardGenerateTest.
 *
 * Every case compares two states. A packer that zips the organization's whole
 * history satisfies "the filtered event's document is in there"; the absent
 * second event is what makes the assertion mean something.
 */
class CertificateZipTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Both halves of the feature go through Storage::disk('r2') —
        // CertificateService::storage() — so faking it covers the write in
        // zip(), the reads of each PDF, and the streamed download.
        Storage::fake('r2');
    }

    /**
     * An issued certificate whose PDF really sits on the disk, which is the
     * state `CertificateService::store()` leaves behind.
     */
    private function certificate(Organization $org, Event $event, string $number, bool $withPdf = true): Certificate
    {
        $certificate = Certificate::create([
            'organization_id' => $org->id,
            'event_id' => $event->id,
            'certificate_number' => $number,
            'recipient_type' => 'player',
            'recipient_id' => (string) Str::uuid(),
            'recipient_name' => 'Ahmad Fauzi',
            'award_title' => 'Juara 1',
            'issued_at' => now(),
        ]);

        if ($withPdf) {
            $key = 'certificates/'.$certificate->id.'.pdf';
            Storage::disk('r2')->put($key, '%PDF-1.4 fake');
            $certificate->update(['pdf_key' => $key]);
        }

        return $certificate;
    }

    private function zip(User $user, Organization $org, ?string $eventId = null): TestResponse
    {
        return $this->actingAs($user, 'api')->postJson(
            "/api/v1/organizations/{$org->id}/certificates/zip",
            $eventId ? ['event_id' => $eventId] : [],
        );
    }

    /**
     * The entry names inside the zip a finished batch produced.
     *
     * Read through the download endpoint rather than off the disk, so the test
     * exercises the same disk branch the browser would.
     *
     * @return array<int, string>
     */
    private function entriesOf(User $user, Organization $org, string $batchId): array
    {
        $response = $this->actingAs($user, 'api')
            ->get("/api/v1/organizations/{$org->id}/certificate-batches/{$batchId}/download")
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'certziptest').'.zip';
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($path);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $zip->close();
        @unlink($path);

        return $names;
    }

    public function test_the_event_filter_narrows_the_zip_and_omitting_it_takes_everything(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        $here = $this->eventOn($org);
        $there = $this->eventOn($org);

        $this->certificate($org, $here, 'CERT-2026-07-0001');
        $this->certificate($org, $there, 'CERT-2026-07-0002');

        $filtered = $this->zip($user, $org, $here->id)->assertStatus(202)->json('data.batch_id');

        // Two halves, and the second is the point: a packer that ignores
        // `event_id` and zips the organization's whole history passes any
        // assertion about the first document on its own.
        $names = $this->entriesOf($user, $org, $filtered);
        $this->assertSame(['CERT-2026-07-0001.pdf'], $names);

        $all = $this->zip($user, $org)->assertStatus(202)->json('data.batch_id');

        $names = $this->entriesOf($user, $org, $all);
        $this->assertCount(2, $names);
        $this->assertContains('CERT-2026-07-0001.pdf', $names);
        $this->assertContains('CERT-2026-07-0002.pdf', $names);
    }

    public function test_a_row_without_a_pdf_is_skipped_but_a_batch_of_only_those_is_refused(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        $partial = $this->eventOn($org);
        $this->certificate($org, $partial, 'CERT-2026-07-0010');
        $this->certificate($org, $partial, 'CERT-2026-07-0011', withPdf: false);

        $batch = $this->zip($user, $org, $partial->id)->assertStatus(202);

        // `total` is files, not rows: it is what the progress bar counts down
        // and what the organizer is told they are getting.
        $batch->assertJsonPath('data.total', 1);
        $this->assertSame(['CERT-2026-07-0010.pdf'], $this->entriesOf($user, $org, $batch->json('data.batch_id')));

        // The other half. Skipping is only the right answer while something
        // survives — an empty archive downloads as a broken file and tells the
        // organizer nothing, so nothing to pack is a refusal.
        $empty = $this->eventOn($org);
        $this->certificate($org, $empty, 'CERT-2026-07-0012', withPdf: false);

        $this->zip($user, $org, $empty->id)->assertStatus(422);
    }

    public function test_another_organization_cannot_read_a_batch(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner, 'Punya A');
        $event = $this->eventOn($org);
        $this->certificate($org, $event, 'CERT-2026-07-0020');

        $batch = $this->zip($owner, $org)->assertStatus(202)->json('data.batch_id');

        $stranger = User::factory()->create();
        $otherOrg = $this->orgFor($stranger, 'Punya B');

        // The batch id is a UUID in a shared cache, so without the ownership
        // check holding one would be enough to read another organizer's
        // certificates. 404 rather than 403 — whether an id exists is itself not
        // something a stranger should learn.
        $this->actingAs($stranger, 'api')
            ->getJson("/api/v1/organizations/{$otherOrg->id}/certificate-batches/{$batch}")
            ->assertNotFound();

        $this->actingAs($stranger, 'api')
            ->get("/api/v1/organizations/{$otherOrg->id}/certificate-batches/{$batch}/download")
            ->assertNotFound();

        // The same two reads succeed for the organization that owns it, which is
        // what proves the refusal was about ownership and not about the batch
        // having expired or never existed.
        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/certificate-batches/{$batch}")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->actingAs($owner, 'api')
            ->get("/api/v1/organizations/{$org->id}/certificate-batches/{$batch}/download")
            ->assertOk();
    }

    public function test_it_is_not_gated_on_the_plan_so_documents_stay_downloadable(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        // One organization, two events. Only the plan differs, so the pair of
        // outcomes cannot be explained by anything else — and the refused-plan
        // half is the assertion that matters: a certificate issued under a plan
        // that was paid for must stay downloadable after an upgrade chain or a
        // catalogue edit takes the feature away, which is exactly what
        // CertificateController::ensureEnabled()'s docblock promises.
        $planned = $this->eventOn($org);
        $bare = $this->eventOn($org, $this->planWith(['qr_tickets' => 'true']));

        $this->certificate($org, $planned, 'CERT-2026-07-0030');
        $this->certificate($org, $bare, 'CERT-2026-07-0031');

        $this->zip($user, $org, $planned->id)->assertStatus(202);

        $batch = $this->zip($user, $org, $bare->id)->assertStatus(202)->json('data.batch_id');
        $this->assertSame(['CERT-2026-07-0031.pdf'], $this->entriesOf($user, $org, $batch));
    }

    public function test_a_plain_member_operator_may_download_but_a_stranger_may_not(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org);
        $this->certificate($org, $event, 'CERT-2026-07-0040');

        // These routes sit in the plain `tenant` group, next to the per-document
        // download they repeat — not behind `org.admin`. Both halves: the
        // operator succeeds, and someone with no membership at all is still shut
        // out, which is what proves `tenant` is doing any work here.
        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->zip($operator, $org)->assertStatus(202);

        $this->zip(User::factory()->create(), $org)->assertForbidden();
    }

    public function test_the_zip_never_reaches_past_its_own_organization(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner, 'Punya A');
        $mine = $this->eventOn($org);
        $this->certificate($org, $mine, 'CERT-2026-07-0050');

        $stranger = User::factory()->create();
        $otherOrg = $this->orgFor($stranger, 'Punya B');
        $theirs = $this->eventOn($otherOrg);
        $this->certificate($otherOrg, $theirs, 'CERT-2026-07-0051');

        // Naming someone else's event is a 404, not a quietly empty zip: the
        // event is resolved through `$org->events()`.
        $this->zip($owner, $org, $theirs->id)->assertNotFound();

        // And the unfiltered call — the one with no event to scope it — still
        // only ever sees its own rows. Without the second half, a `whereNotNull`
        // over the whole table would pass the 404 above untouched.
        $batch = $this->zip($owner, $org)->assertStatus(202)->json('data.batch_id');
        $this->assertSame(['CERT-2026-07-0050.pdf'], $this->entriesOf($owner, $org, $batch));
    }

    public function test_the_batch_cannot_be_downloaded_before_it_finishes(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        // Written straight into the cache rather than dispatched: under
        // QUEUE_CONNECTION=sync every real batch is already 'done' by the time
        // the POST returns, so a queued one is the only way to reach the 409 —
        // which is the state a production poller sees for its first second or two.
        Cache::put(
            GenerateCertificateZipJob::cacheKey('pending-batch'),
            ['status' => 'queued', 'organization_id' => $org->id, 'total' => 3, 'done' => 0, 'key' => null],
            now()->addHour(),
        );

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/certificate-batches/pending-batch")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/certificate-batches/pending-batch/download")
            ->assertStatus(409);
    }

    public function test_the_list_pages_and_its_total_counts_the_whole_filter(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org);

        foreach (range(1, 3) as $n) {
            $this->certificate($org, $event, sprintf('CERT-2026-07-01%02d', $n));
        }

        // Two pages of two, so the second page is short — that is what proves
        // `last_page` is read off the paginator rather than guessed from a full
        // page of rows.
        $first = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/certificates?per_page=2")
            ->assertOk();

        $first->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.page', 1)
            ->assertJsonPath('data.meta.last_page', 2)
            // The count over the filter, not the page: the bulk-zip button reads
            // it to decide whether there is anything to pack, and a per-page
            // figure would call a three-row list "2".
            ->assertJsonPath('data.meta.total', 3);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/certificates?per_page=2&page=2")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.meta.page', 2)
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_the_list_filter_narrows_the_total_too(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);

        $here = $this->eventOn($org);
        $there = $this->eventOn($org);

        $this->certificate($org, $here, 'CERT-2026-07-0200');
        $this->certificate($org, $there, 'CERT-2026-07-0201');
        $this->certificate($org, $there, 'CERT-2026-07-0202');

        // Both halves. `total` is what the page's empty state and its zip button
        // branch on, so a total that ignored `event_id` would offer a download
        // of the whole history from a filter showing one row.
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/certificates?event_id={$here->id}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/certificates")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_the_cap_bounds_one_batch(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org);

        config(['certificate.max_batch' => 2]);

        $this->certificate($org, $event, 'CERT-2026-07-0060');
        $this->certificate($org, $event, 'CERT-2026-07-0061');
        $this->certificate($org, $event, 'CERT-2026-07-0062');

        // Three rows, a cap of two. The cap is what keeps a batch bounded for an
        // organization with years of events behind it; without reading it back
        // from config here, a hardcoded limit would pass just as well.
        $this->zip($user, $org, $event->id)
            ->assertStatus(202)
            ->assertJsonPath('data.total', 2);
    }
}
