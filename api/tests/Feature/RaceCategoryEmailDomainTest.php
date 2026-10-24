<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * A race category that only a campus address may enter: "Mahasiswa UB" sells
 * cheaper than "Umum", so the cheaper row needs a door.
 *
 * Every test here **compares** two things that have to come out different, the
 * house style of RaceEntryTest and for the same reason: "the entry was refused"
 * stays green when the gate refuses everybody, when the matching is by suffix,
 * and when normalisation never runs. The pairs are what pin it down.
 */
class RaceCategoryEmailDomainTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
        Mail::fake();
        // Inside Early Bird below, pinned for the reason RaceEntryTest pins it:
        // half of what a race asserts is "which window was in force".
        Carbon::setTestNow('2026-01-05 03:00:00'); // 10:00 WIB
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----

    private function raceEvent(Organization $org): Event
    {
        $event = $this->eventOn($org, null, [
            'sport_type' => 'fun_run',
            'status' => 'open',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
        ]);

        $event->racePeriods()->create([
            'name' => 'Early Bird',
            'starts_at' => '2026-01-01T00:00:00+07:00',
            'ends_at' => '2026-01-10T23:59:59+07:00',
            'sort_order' => 0,
        ]);

        return $event->fresh();
    }

    /** @param list<string>|null $domains */
    private function category(Event $event, string $name, ?array $domains = null): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'email_domains' => $domains,
        ]);

        $category->prices()->create([
            'race_period_id' => $event->racePeriods()->firstOrFail()->id,
            'price' => 75_000,
        ]);

        return $category;
    }

    private function register(Organization $org, Event $event, RaceCategory $category, string $email): TestResponse
    {
        return $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar',
                'email' => $email,
                'payment_channel' => 'va',
            ]);
    }

    // ---- the gate ----

    /**
     * The gate refuses the restricted category and leaves the open one alone.
     *
     * Two categories in ONE event, so nothing about the event can explain the
     * difference. Asserting only the 422 would pass a build whose gate refuses
     * every address — including on the categories that have no condition, which
     * is every category that exists today.
     */
    public function test_a_restricted_category_refuses_an_outside_email_and_an_open_one_does_not(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);

        $campus = $this->category($event, 'Mahasiswa UB', ['ub.ac.id']);
        $open = $this->category($event, 'Umum');

        $refused = $this->register($org, $event, $campus, 'ammar@gmail.com');
        $refused->assertStatus(422);
        // Field `email`, never `errors.feature`: with that key
        // isPlanLimitError() swallows it into a toast and the input never turns
        // red. The lesson is already written for `discount_code`.
        $refused->assertJsonPath('errors.email.0', fn ($msg) => str_contains((string) $msg, '@ub.ac.id'));
        $this->assertNull($refused->json('errors.feature'));

        $this->register($org, $event, $open, 'ammar@gmail.com')->assertStatus(201);
    }

    /**
     * Matching is EXACT, so a subdomain is refused.
     *
     * The pair is the whole test: `nama@ub.ac.id` accepted beside
     * `nama@student.ub.ac.id` refused, on one category. Asserting only the
     * accepted address passes a `str_ends_with()` build, which would also admit
     * `@notub.ac.id` — and that hole is the reason this is exact.
     */
    public function test_a_subdomain_is_refused_while_the_exact_domain_is_accepted(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $campus = $this->category($event, 'Mahasiswa UB', ['ub.ac.id']);

        $this->register($org, $event, $campus, 'ammar@student.ub.ac.id')->assertStatus(422);
        // The hole a suffix match would open, asserted beside it.
        $this->register($org, $event, $campus, 'ammar@notub.ac.id')->assertStatus(422);

        $this->register($org, $event, $campus, 'ammar@ub.ac.id')->assertStatus(201);
    }

    /**
     * A refused entry writes nothing.
     *
     * The gate sits before the quote for the same reason the quote sits before
     * the first write: a refused entry must burn no invoice number and must not
     * leave a place held. Compared against the accepted one, because "no rows"
     * is also what a build that never reaches the endpoint produces.
     */
    public function test_a_refused_entry_holds_no_place(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $campus = $this->category($event, 'Mahasiswa UB', ['ub.ac.id']);

        $this->register($org, $event, $campus, 'ammar@gmail.com')->assertStatus(422);
        $this->assertSame(0, $campus->fresh()->taken);
        $this->assertDatabaseCount('race_entries', 0);

        $this->register($org, $event, $campus, 'ammar@ub.ac.id')->assertStatus(201);
        $this->assertSame(1, $campus->fresh()->taken);
    }

    /**
     * The runner's own address is normalised too, not just the organizer's.
     *
     * Read-side normalisation is what makes a single speller worth having: a
     * build that normalised only on write stores `ub.ac.id` and then compares
     * `AMMAR@UB.AC.ID` against it, and the refusal is invisible because the
     * stored value looks right on every screen.
     */
    public function test_the_runners_address_is_matched_case_insensitively(): void
    {
        $org = $this->orgFor(User::factory()->create());
        $event = $this->raceEvent($org);
        $campus = $this->category($event, 'Mahasiswa UB', ['ub.ac.id']);

        $this->register($org, $event, $campus, 'Ammar@UB.AC.ID')->assertStatus(201);
    }

    // ---- the organizer's side ----

    /** The conditions reach the client; without this the proactive gate cannot exist. */
    public function test_the_conditions_are_published_to_the_runner(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $this->category($event, 'Mahasiswa UB', ['ub.ac.id']);
        $this->category($event, 'Umum');

        $payload = $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race")
            ->assertOk()
            ->json('data.categories');

        $byName = collect($payload)->keyBy('name');

        $this->assertSame(['ub.ac.id'], $byName['Mahasiswa UB']['email_domains']);
        // `[]`, not null: one shape to read on the client. Compared, because a
        // resource that published null for the unrestricted one would make the
        // client's `?? []` the second reader of the invariant.
        $this->assertSame([], $byName['Umum']['email_domains']);
    }

    /**
     * Three spellings of one domain are stored once.
     *
     * Asserting that `ub.ac.id` is in the stored list would pass even if
     * normalisation never ran — the plain spelling is in the payload. The count
     * is what proves it: `@UB.AC.ID` and `https://ub.ac.id/` have to fold into
     * the same entry, or the organizer's list grows a duplicate nobody can tell
     * apart on screen.
     */
    public function test_the_three_spellings_of_one_domain_collapse_into_one(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);

        $created = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-categories", [
                'name' => 'Mahasiswa UB',
                'email_domains' => ['@UB.AC.ID', ' ub.ac.id ', 'https://ub.ac.id/', 'nama@student.ub.ac.id'],
                'prices' => [],
            ])
            ->assertStatus(201)
            ->json('data');

        // A whole address pasted into the field keeps only its domain — the
        // mistake normalizeDomain() is there to make harmless.
        $this->assertSame(['ub.ac.id', 'student.ub.ac.id'], $created['email_domains']);
    }

    /** A chip that is not a domain is named, not silently dropped. */
    public function test_a_value_that_is_not_a_domain_is_refused(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-categories", [
                'name' => 'Mahasiswa UB',
                // A suffix rule an organizer may reach for. Refused because
                // matching is exact and a wildcard here would read as working.
                'email_domains' => ['*.ub.ac.id'],
                'prices' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email_domains.0');
    }

    /**
     * An empty list is stored as null, and that category admits anyone.
     *
     * Both halves, because the column's invariant has two: `[]` is never
     * stored (two spellings of one answer are two branches that will disagree),
     * and "no value" must read as OPEN here — the opposite of
     * `race_category_prices`, where an absent row refuses.
     */
    public function test_an_empty_list_is_stored_as_null_and_admits_anyone(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);

        $created = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/race-categories", [
                'name' => 'Umum',
                'email_domains' => [],
                'prices' => [[
                    'race_period_id' => $event->racePeriods()->firstOrFail()->id,
                    'price' => 75_000,
                ]],
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertNull(RaceCategory::findOrFail($created['id'])->email_domains);

        $this->register($org, $event, RaceCategory::findOrFail($created['id']), 'ammar@gmail.com')
            ->assertStatus(201);
    }

    /**
     * An absent key leaves the conditions alone; `[]` clears them.
     *
     * The pair is the point, the same one `official_stats` documents: a client
     * that never heard of this field must be able to rename a category without
     * quietly opening it to everyone, and the dialog that owns the field must
     * be able to empty it. Asserting only the rename would pass a `?? []`
     * build, which erases the condition with a green toast.
     */
    public function test_an_absent_key_keeps_the_conditions_and_an_empty_array_clears_them(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Mahasiswa UB', ['ub.ac.id']);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/race-categories/{$category->id}", [
                'name' => 'Mahasiswa UB (5K)',
            ])
            ->assertOk();

        $this->assertSame(['ub.ac.id'], $category->fresh()->emailDomains());

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/race-categories/{$category->id}", [
                'email_domains' => [],
            ])
            ->assertOk();

        $this->assertNull($category->fresh()->email_domains);
        $this->register($org, $event, $category->fresh(), 'ammar@gmail.com')->assertStatus(201);
    }
}
