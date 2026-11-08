<?php

namespace Tests\Feature;

use App\Jobs\GenerateBibsJob;
use App\Jobs\SendBibsJob;
use App\Mail\BibIssuedMail;
use App\Models\BibTemplate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\User;
use App\Services\BibService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The organizer's print and send doors, and the runner's own download.
 *
 * Every gate here is compared against the state that must behave differently,
 * because none of them can be proved alone: "the batch was queued" stays green
 * on a controller that queues for a football event too, "the mail went out"
 * stays green on one that stamps `bib_sent_at` before the transport sees it,
 * and "the runner got their sheet" stays green on an endpoint that would hand
 * over anybody's.
 */
class BibBatchAndSendTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        Carbon::setTestNow('2026-01-05 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----

    private function raceEvent(Organization $org, array $attrs = []): Event
    {
        return $this->eventOn($org, null, [
            'sport_type' => 'fun_run',
            'status' => 'open',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
            ...$attrs,
        ]);
    }

    private function category(Event $event, string $name = '5K'): RaceCategory
    {
        return $event->raceCategories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => 1,
        ]);
    }

    private function template(Organization $org): BibTemplate
    {
        $png = (new ImageManager(new GdDriver))->createImage(600, 420)->fill('#ffffff')->encode(new PngEncoder);
        Storage::disk('public')->put('bibs/bg.png', (string) $png);

        return $org->bibTemplates()->create([
            'name' => 'BIB',
            'background_url' => Storage::disk('public')->url('bibs/bg.png'),
            'width_mm' => 210,
            'height_mm' => 148,
            'fields' => [
                ['key' => 'bib_number', 'x' => 50, 'y' => 25, 'size' => 50, 'align' => 'center', 'bold' => true],
                ['key' => 'qr', 'x' => 75, 'y' => 60, 'w' => 18, 'h' => 18],
            ],
        ]);
    }

    private function entry(
        Event $event,
        RaceCategory $category,
        string $name,
        string $status = 'paid',
        ?string $email = null,
    ): RaceEntry {
        return RaceEntry::create([
            'event_id' => $event->id,
            'race_category_id' => $category->id,
            'full_name' => $name,
            'email' => $email ?? Str::slug($name).'@test.com',
            'period_name' => 'Early Bird',
            'price' => 75_000,
            'status' => $status,
            'payment_method' => 'gateway',
            'qr_code' => $status === 'paid' ? 'RUN'.Str::upper(Str::random(8)) : null,
        ]);
    }

    /** @return array{0: User, 1: Organization, 2: Event, 3: RaceCategory} */
    private function scene(): array
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);

        return [$owner, $org, $event, $this->category($event)];
    }

    // ---- the two gates ----

    /**
     * Invariant: printing is gated TWICE, and the two gates ask different
     * questions.
     *
     * Compared in one test, because each refusal passes on a controller that
     * only has the other gate: a plan check alone offers e-BIB on a football
     * event, and a sport check alone hands the top-tier feature to Pro.
     */
    public function test_printing_needs_both_the_plan_and_a_race_event(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        // A race on a plan that does not carry the feature.
        $unpaid = $this->raceEvent($org, []);
        $unpaid->update(['plan_id' => $this->planWith(['max_categories' => '-1'])->id]);

        // A football event on the full plan — entitled, but has no runners.
        $football = $this->eventOn($org, null, ['sport_type' => 'football']);

        $template = $this->template($org);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$unpaid->id}/bibs", [
                'bib_template_id' => $template->id,
                'entry_ids' => [(string) Str::uuid()],
            ])
            ->assertForbidden()
            ->assertJsonPath('errors.feature', 'bib_generator');

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$football->id}/bibs", [
                'bib_template_id' => $template->id,
                'entry_ids' => [(string) Str::uuid()],
            ])
            ->assertForbidden()
            // Same key, different sentence: an organizer told "paket tidak
            // mendukung" on a football event would go buy a plan that changes
            // nothing.
            ->assertJsonPath('message', 'e-BIB hanya untuk event cabang tanpa pertandingan.');
    }

    /**
     * Ids are resolved against the event's own pool, never trusted.
     *
     * Compared against the same request naming this event's runner, because a
     * 422 on its own passes on a controller that refuses everything.
     */
    public function test_an_entry_from_another_event_is_not_printed(): void
    {
        [$owner, $org, $event, $category] = $this->scene();

        $other = $this->raceEvent($org);
        $foreign = $this->entry($other, $this->category($other), 'Orang Lain');
        $mine = $this->entry($event, $category, 'Ammar');

        $template = $this->template($org);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/bibs", [
                'bib_template_id' => $template->id,
                'entry_ids' => [$foreign->id],
            ])
            ->assertStatus(422);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/bibs", [
                'bib_template_id' => $template->id,
                'entry_ids' => [$mine->id],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.total', 1);
    }

    /**
     * Generating assigns the numbers, so an organizer never prints a sheet with
     * a blank number field — see BibService::assignNumbersForEvent().
     */
    public function test_generating_numbers_the_field_and_reports_how_many_are_new(): void
    {
        [$owner, $org, $event, $category] = $this->scene();

        $runner = $this->entry($event, $category, 'Ammar');
        $this->assertNull($runner->bib_number);

        $template = $this->template($org);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/bibs", [
                'bib_template_id' => $template->id,
                'entry_ids' => [$runner->id],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.numbered', 1);

        $this->assertSame('5K-001', $runner->fresh()->bib_number);

        // A second print reports zero new numbers rather than renumbering —
        // the controller-level half of the numbering invariant.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/bibs", [
                'bib_template_id' => $template->id,
                'entry_ids' => [$runner->id],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.numbered', 0);

        $this->assertSame('5K-001', $runner->fresh()->bib_number);
    }

    /**
     * Invariant: only PAID entries are printed.
     *
     * Compared against a paid runner in the same category, because an unpaid
     * entry has no `qr_code` — so its sheet would be a race-pack ticket that
     * admits nobody and looks exactly like one that works.
     */
    public function test_an_unpaid_entry_is_not_offered_or_printed(): void
    {
        [$owner, $org, $event, $category] = $this->scene();

        $pending = $this->entry($event, $category, 'Dimas', status: 'pending');
        $paid = $this->entry($event, $category, 'Ammar');

        $ids = collect(
            $this->actingAs($owner, 'api')
                ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/bib-recipients")
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$paid->id], $ids);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/bibs", [
                'bib_template_id' => $this->template($org)->id,
                'entry_ids' => [$pending->id],
            ])
            ->assertStatus(422);
    }

    // ---- the batch job ----

    /**
     * The job renders, parks one object per runner, and finishes downloadable.
     *
     * Both halves of "rendered once, used twice" are asserted: the zip exists
     * AND each entry names its own PNG. A job that only built the zip passes
     * any download test while leaving the email and the runner's own page with
     * nothing to attach.
     */
    public function test_the_job_writes_both_the_zip_and_a_png_per_runner(): void
    {
        [, $org, $event, $category] = $this->scene();

        $a = $this->entry($event, $category, 'Ammar');
        $b = $this->entry($event, $category, 'Dimas');
        $template = $this->template($org);

        $bibs = app(BibService::class);
        $bibs->assignNumbersForEvent($event);

        $batchId = (string) Str::uuid();
        (new GenerateBibsJob($batchId, $event->id, $template->id, $bibs->recipients($event)))->handle($bibs);

        $payload = cache()->get(GenerateBibsJob::cacheKey($batchId));

        $this->assertSame('done', $payload['status']);
        $this->assertSame(2, $payload['done']);
        Storage::disk('public')->assertExists($payload['key']);

        foreach ([$a, $b] as $entry) {
            $key = $entry->fresh()->bib_png_key;
            $this->assertNotNull($key, 'No per-runner PNG was parked.');
            Storage::disk('public')->assertExists($key);
        }
    }

    /**
     * A batch id belonging to someone else is a 404, not a 403: whether an id
     * exists is itself not something a stranger should learn.
     */
    public function test_a_foreign_batch_is_not_readable(): void
    {
        [, $org, $event] = $this->scene();

        $intruderOwner = User::factory()->create();
        $intruderOrg = $this->orgFor($intruderOwner, 'Lain');

        $batchId = (string) Str::uuid();
        cache()->put(GenerateBibsJob::cacheKey($batchId), [
            'status' => 'done',
            'organization_id' => $org->id,
            'event_id' => $event->id,
            'key' => 'bib-batches/x.zip',
        ], now()->addHour());

        $this->actingAs($intruderOwner, 'api')
            ->getJson("/api/v1/organizations/{$intruderOrg->id}/bib-batches/{$batchId}")
            ->assertNotFound();
    }

    // ---- sending ----

    /**
     * Invariant: one mail per RUNNER, which is what makes this feature need no
     * grouping step.
     *
     * The count is compared against the number of runners, not asserted as
     * "at least one": a job grouping by address — copied from the card feature
     * without thinking — would collapse a family sharing one inbox into one
     * mail and silently drop the other sheets.
     */
    public function test_each_runner_gets_their_own_mail(): void
    {
        [$owner, $org, $event, $category] = $this->scene();

        $this->entry($event, $category, 'Ammar');
        $this->entry($event, $category, 'Dimas');
        $this->entry($event, $category, 'Budi');

        $template = $this->template($org);
        $bibs = app(BibService::class);
        $bibs->assignNumbersForEvent($event);
        $bibs->zip($event, $template, $bibs->recipients($event));

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/bibs/send")
            ->assertOk()
            ->assertJsonPath('data.emails', 3)
            ->assertJsonPath('data.skipped', 0);

        // The suite runs the queue `sync` (tests/bootstrap.php), so dispatch
        // already ran each job inline — counting the mails needs no second run,
        // and doing one would double every figure here.
        Mail::assertSent(BibIssuedMail::class, 3);
    }

    /**
     * Invariant: `bib_sent_at` is stamped only after the transport accepts.
     *
     * Compared failure against success on the same entry, which is the only way
     * to see it: asserting the stamp exists after a successful send passes
     * identically on a job that writes it first and leaves a failed mail
     * looking delivered — unresendable, with nothing on screen to say so.
     */
    public function test_a_transport_failure_leaves_the_bib_resendable(): void
    {
        [, $org, $event, $category] = $this->scene();

        $failing = $this->entry($event, $category, 'Ammar');
        $working = $this->entry($event, $category, 'Dimas');

        $template = $this->template($org);
        $bibs = app(BibService::class);
        $bibs->assignNumbersForEvent($event);
        $bibs->zip($event, $template, $bibs->recipients($event));

        // The working half FIRST, while the faked transport is still in place:
        // once Mail is swapped for a mock that throws, there is no putting the
        // fake back within one test (Mail::fake() rebuilds from the container's
        // manager, which the mock replaced).
        (new SendBibsJob($working->id))->handle();

        $this->assertNotNull(
            $working->fresh()->bib_sent_at,
            'Nothing writes the column, so the failure assertion below proves nothing.',
        );

        Mail::shouldReceive('to->sendNow')->once()->andThrow(new \RuntimeException('smtp down'));

        try {
            (new SendBibsJob($failing->id))->handle();
            $this->fail('The job swallowed a transport failure.');
        } catch (\RuntimeException) {
            // expected: the throw is what leaves the job retryable
        }

        $this->assertNull($failing->fresh()->bib_sent_at);
    }

    /**
     * An entry whose sheet was never rendered is not sent, and the gap is
     * REPORTED rather than swallowed.
     */
    public function test_entries_without_a_rendered_sheet_are_not_sent(): void
    {
        [$owner, $org, $event, $category] = $this->scene();

        $printed = $this->entry($event, $category, 'Ammar');
        $this->entry($event, $category, 'Dimas');

        $template = $this->template($org);
        $bibs = app(BibService::class);
        $bibs->assignNumbersForEvent($event);

        // Only one of the two is rendered.
        $one = collect($bibs->recipients($event))->firstWhere('id', $printed->id);
        $bibs->zip($event, $template, [$one]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/bibs/send")
            ->assertOk()
            ->assertJsonPath('data.emails', 1);
    }

    // ---- the runner's own download ----

    /**
     * The runner's door, and `bib_available` beside it.
     *
     * Compared before and after the print, because asserting the download works
     * afterwards passes on a resource that hardcodes the flag true — and a page
     * offering a button over a 404 is worse than one offering nothing.
     */
    public function test_a_runner_can_download_their_own_sheet_once_it_exists(): void
    {
        [, $org, $event, $category] = $this->scene();

        $runner = $this->entry($event, $category, 'Ammar');

        $this->getJson("/api/v1/race-entries/{$runner->id}")
            ->assertOk()
            ->assertJsonPath('data.bib_available', false);

        $this->getJson("/api/v1/race-entries/{$runner->id}/bib")->assertNotFound();

        $bibs = app(BibService::class);
        $bibs->assignNumbersForEvent($event);
        $bibs->zip($event, $this->template($org), $bibs->recipients($event));

        $this->getJson("/api/v1/race-entries/{$runner->id}")
            ->assertOk()
            ->assertJsonPath('data.bib_available', true);

        $this->get("/api/v1/race-entries/{$runner->id}/bib")
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }
}
