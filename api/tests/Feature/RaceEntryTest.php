<?php

namespace Tests\Feature;

use App\Exports\RaceEntriesExport;
use App\Models\Event;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\RaceEntry;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\RefundService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Fun run entries end to end: registering, paying, and what the money does.
 *
 * Every uji here **compares** two things that must come out different, because
 * nearly nothing in this flow can be proven with one assert. "The entry was
 * created" stays green when the price is read from the wrong period, when
 * manual money is credited to the wallet, and when the webhook arm never fires.
 * The pairs are what pin it down.
 */
class RaceEntryTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
        Mail::fake();

        // Inside Early Bird below. Pinned because half of what is asserted here
        // is "which window was in force", and a wall clock would make the suite
        // say something different every month.
        Carbon::setTestNow('2026-01-05 03:00:00'); // 10:00 WIB
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----

    private function gateway(bool $enabled): void
    {
        PlatformSettings::put(['payment_gateway_enabled' => $enabled], null);
        PlatformSettings::flush();
    }

    private function bankAccount(Organization $org): void
    {
        $org->bankAccounts()->create([
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => '1234567890',
            'account_holder' => 'Flo Event EO',
            'is_primary' => true,
        ]);
    }

    /**
     * `$plan` must be passed explicitly when it is the subject: eventOn() falls
     * back to fullPlan() on null and does NOT read $this->testPlan, so a
     * restricted plan set on the trait alone would be silently ignored and the
     * gate test would pass against a permissive plan.
     */
    private function raceEvent(Organization $org, ?\App\Models\Plan $plan = null): Event
    {
        $event = $this->eventOn($org, $plan, [
            'sport_type' => 'fun_run',
            'status' => 'open',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
        ]);

        foreach ([
            ['Early Bird', '2026-01-01T00:00:00+07:00', '2026-01-10T23:59:59+07:00'],
            ['Pre Sale', '2026-01-11T00:00:00+07:00', '2026-01-20T23:59:59+07:00'],
        ] as $i => [$name, $from, $to]) {
            $event->racePeriods()->create([
                'name' => $name, 'starts_at' => $from, 'ends_at' => $to, 'sort_order' => $i,
            ]);
        }

        return $event->fresh();
    }

    /** @param array<string, float> $prices period name => price */
    private function category(Event $event, string $name, array $prices, ?int $quota = null): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'quota' => $quota,
        ]);

        foreach ($prices as $periodName => $price) {
            $category->prices()->create([
                'race_period_id' => $event->racePeriods()->where('name', $periodName)->firstOrFail()->id,
                'price' => $price,
            ]);
        }

        return $category;
    }

    /** @return array<string, mixed> the register response payload */
    private function register(User $runner, Organization $org, Event $event, RaceCategory $category, array $overrides = []): array
    {
        return $this->actingAs($runner, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar',
                'email' => 'ammar@test.com',
                'phone' => '08123456789',
                'payment_channel' => 'va',
                ...$overrides,
            ])
            ->json('data');
    }

    // ---- registering ----

    /**
     * The emergency contact's three fields survive the whole chain — request
     * rule, service, column, resource.
     *
     * The relation is the one that needs asserting: the pair beside it was
     * already wired, so a build that dropped the new column would keep every
     * other race test green. Compared against an entry that omits it, because
     * the field is nullable on purpose (whether to demand it is the organizer's
     * call, made on their registration form) — asserting only the filled one
     * would pass a rule that had quietly become `required`.
     */
    public function test_the_emergency_contact_relation_is_stored_and_published(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $filled = $this->register(User::factory()->create(), $org, $event, $category, [
            'emergency_contact_name' => 'Siti Aminah',
            'emergency_contact_phone' => '08998877665',
            'emergency_contact_relation' => 'Ibu',
        ]);

        // Published, not just stored: the organizer's list and the export read
        // the resource, and a column nothing emits is a column nobody can call.
        $this->assertSame('Ibu', $filled['entry']['emergency_contact_relation']);
        $this->assertSame('Ibu', RaceEntry::findOrFail($filled['entry']['id'])->emergency_contact_relation);
        // Free text, never an enum — see the column. "Ibu" is stored as typed,
        // not uppercased the way a roster name would be.
        $this->assertNotSame('IBU', $filled['entry']['emergency_contact_relation']);

        $omitted = $this->register(User::factory()->create(), $org, $event, $category);

        $this->assertNull($omitted['entry']['emergency_contact_relation']);
        $this->assertNull(RaceEntry::findOrFail($omitted['entry']['id'])->emergency_contact_relation);
    }

    /**
     * The entrant sheet's headings and its values line up.
     *
     * `headings()` and `rows()` are computed separately, so a column added to
     * one and not the other prints every later value one column to the left —
     * silently, in a file an organizer hands to a race marshal. The assertion
     * is therefore positional: the heading's index is looked up and the value
     * at that same index is checked, which is the only form that catches an
     * off-by-one. Asserting "the sheet contains Ibu" somewhere would pass with
     * the column landing under Periode.
     *
     * Two entries, one with the relation and one without, so the `'—'`
     * placeholder is exercised in the same alignment it has to keep.
     */
    public function test_the_export_puts_the_relation_under_its_own_heading(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $this->register(User::factory()->create(), $org, $event, $category, [
            'full_name' => 'Ammar',
            'emergency_contact_name' => 'Siti Aminah',
            'emergency_contact_relation' => 'Ibu',
        ]);
        $this->register(User::factory()->create(), $org, $event, $category, [
            'full_name' => 'Budi',
            'email' => 'budi@test.com',
        ]);

        $export = new RaceEntriesExport($event->fresh());
        $headings = $export->headings();
        $rows = $export->rows();

        $at = array_search('Hubungan', $headings, true);
        $this->assertNotFalse($at, 'the sheet has no Hubungan column');

        // Ordered by full_name within the category, so Ammar is first.
        $this->assertSame('Ibu', $rows[0][$at]);
        $this->assertSame('—', $rows[1][$at]);

        // And the neighbours still hold their own ground — the half that proves
        // the column was inserted rather than appended over something else.
        $this->assertSame('Siti Aminah', $rows[0][(int) array_search('Kontak Darurat', $headings, true)]);
        $this->assertSame('Early Bird', $rows[0][(int) array_search('Periode', $headings, true)]);
        $this->assertCount(count($headings), $rows[0]);
    }

    /**
     * The price charged is the one in force, and the snapshot is what is
     * stored. Compared across a period boundary: a single registration proves
     * nothing about which window was read.
     */
    public function test_the_price_charged_is_the_window_in_force(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000, 'Pre Sale' => 90_000]);

        $early = $this->register(User::factory()->create(), $org, $event, $category);

        Carbon::setTestNow('2026-01-15 03:00:00'); // inside Pre Sale
        $late = $this->register(User::factory()->create(), $org, $event, $category);

        $this->assertEquals(75_000, $early['entry']['price']);
        $this->assertSame('Early Bird', $early['entry']['period_name']);
        // The pair that binds it. Asserting only the first would pass even if
        // the period were taken from the payload, or always the first row.
        $this->assertEquals(90_000, $late['entry']['price']);
        $this->assertSame('Pre Sale', $late['entry']['period_name']);
    }

    /**
     * Invarian snapshot: mengedit harga periode tidak mengubah entry yang sudah
     * terbit, dan menghapus periodenya pun tidak.
     *
     * Dibandingkan sebelum/sesudah dalam satu test. Assert "harganya 75.000"
     * saja akan lolos walau harganya diturunkan dari FK — di test satu langkah,
     * harganya memang belum berubah.
     */
    public function test_an_issued_entry_keeps_its_price_when_the_period_changes(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $entryId = $this->register(User::factory()->create(), $org, $event, $category)['entry']['id'];
        $before = RaceEntry::findOrFail($entryId);

        // The organizer decides early bird was underpriced.
        $category->prices()->where('race_period_id', $before->race_period_id)->update(['price' => 120_000]);

        $this->assertSame('75000.00', RaceEntry::findOrFail($entryId)->price);
        $this->assertSame('Early Bird', RaceEntry::findOrFail($entryId)->period_name);

        // And the harder half: the window is deleted outright. The FK goes
        // null, the two snapshot columns still read — which is what keeps the
        // receipt printable.
        $event->racePeriods()->whereKey($before->race_period_id)->delete();

        $after = RaceEntry::findOrFail($entryId);
        $this->assertNull($after->race_period_id);
        $this->assertSame('75000.00', $after->price);
        $this->assertSame('Early Bird', $after->period_name);
    }

    public function test_registering_inside_a_gap_is_refused(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000, 'Pre Sale' => 90_000]);

        // Accepted now, inside Early Bird.
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar', 'email' => 'a@test.com', 'payment_channel' => 'va',
            ])
            ->assertCreated();

        // Refused after both windows have closed. The pair is the point: a
        // build that always refused would pass the half above on its own.
        Carbon::setTestNow('2026-01-25 03:00:00');

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Dimas', 'email' => 'd@test.com', 'payment_channel' => 'va',
            ])
            ->assertStatus(422);

        $this->assertSame(1, RaceEntry::count());
    }

    /**
     * A category with no price row for the live window is refused, while one
     * that has it goes through — the two compared in a single request pair, so
     * an absent row read as 0 cannot hide.
     */
    public function test_a_category_not_sold_this_period_is_refused(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);

        $sold = $this->category($event, 'Umum', ['Early Bird' => 75_000]);
        $notYet = $this->category($event, 'Mahasiswa', ['Pre Sale' => 65_000]);

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $sold->id,
                'full_name' => 'Ammar', 'email' => 'a@test.com', 'payment_channel' => 'va',
            ])
            ->assertCreated();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $notYet->id,
                'full_name' => 'Dimas', 'email' => 'd@test.com', 'payment_channel' => 'va',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.race_category_id.0', 'Kategori ini tidak tersedia pada periode pendaftaran saat ini.');
    }

    public function test_quota_is_held_by_pending_entries_and_given_back_when_they_expire(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->bankAccount($org);
        $this->gateway(false);

        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000], quota: 1);

        $first = $this->register(User::factory()->create(), $org, $event, $category);
        $this->assertSame(1, $category->fresh()->taken);

        // The place is held even though nobody has paid: that is what `taken`
        // means, and it is why the sweep below exists.
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Dimas', 'email' => 'd@test.com',
            ])
            ->assertStatus(422);

        // Past the deadline with no proof uploaded.
        RaceEntry::whereKey($first['entry']['id'])->update(['payment_deadline_at' => Carbon::now()->subHour()]);
        $this->artisan('race-entries:expire-manual')->assertSuccessful();

        // Compared against the 1 above: assert "the place came back" alone
        // would pass even if the sweep released places nobody was holding.
        $this->assertSame(0, $category->fresh()->taken);
        $this->assertSame('expired', RaceEntry::findOrFail($first['entry']['id'])->status);
    }

    /**
     * An entry whose runner HAS uploaded proof is never expired. The pair of
     * entries in one sweep is what proves the filter, not the sweep's silence.
     */
    public function test_the_sweep_spares_an_entry_that_has_proof(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->bankAccount($org);
        $this->gateway(false);

        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $abandoned = $this->register(User::factory()->create(), $org, $event, $category)['entry']['id'];
        $waiting = $this->register(User::factory()->create(), $org, $event, $category)['entry']['id'];

        RaceEntry::whereIn('id', [$abandoned, $waiting])->update(['payment_deadline_at' => Carbon::now()->subHour()]);
        $this->postJson("/api/v1/race-entries/{$waiting}/proof", [
            'payment_proof_url' => 'https://cdn.test/bukti.webp',
        ])->assertOk();

        $this->artisan('race-entries:expire-manual')->assertSuccessful();

        $this->assertSame('expired', RaceEntry::findOrFail($abandoned)->status);
        $this->assertSame('pending', RaceEntry::findOrFail($waiting)->status);
    }

    // ---- money ----

    /**
     * Invarian: transfer manual tidak boleh menyentuh dompet.
     *
     * Dibandingkan gateway vs manual pada event yang sama — assert "ledger
     * kosong" sendirian tidak membuktikan apa pun, karena ledger yang selalu
     * kosong lolos juga.
     */
    public function test_manual_money_never_reaches_the_wallet_but_gateway_money_does(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->bankAccount($org);

        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        // Gateway rail: settles immediately (no Midtrans key in tests) and
        // credits the organizer.
        $this->gateway(true);
        $gatewayEntry = RaceEntry::findOrFail($this->register(User::factory()->create(), $org, $event, $category)['entry']['id']);

        // Manual rail: same event, same category, same price.
        $this->gateway(false);
        $manualEntry = RaceEntry::findOrFail($this->register(User::factory()->create(), $org, $event, $category)['entry']['id']);
        $manualEntry->update(['payment_proof_url' => 'https://cdn.test/bukti.webp', 'payment_proof_uploaded_at' => now()]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/payments/race-entries/{$manualEntry->id}/approve")
            ->assertOk();

        $this->assertSame('paid', $gatewayEntry->fresh()->status);
        $this->assertSame('paid', $manualEntry->fresh()->status);

        // One ledger row, not two: the gateway entry's.
        $rows = app(WalletService::class)->forOrganization($org->fresh())->transactions()->get();
        $this->assertCount(1, $rows);
        $this->assertSame($gatewayEntry->id, $rows->first()->source_id);
        $this->assertSame('race_entry', $rows->first()->category);
    }

    /**
     * Invarian `total_earned`: rail keempat wajib ada di EARNING_CATEGORIES.
     *
     * Dibandingkan dengan kredit tiket di event yang sama, dan `total_earned`
     * harus jumlah keduanya. Assert `balance_pending` saja akan lolos walau
     * kategorinya tidak terdaftar — cabang `increment($column)` di atasnya
     * tidak membaca `category` sama sekali.
     */
    public function test_a_race_credit_counts_towards_total_earned(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $this->gateway(true);
        $this->register(User::factory()->create(), $org, $event, $category);

        $wallet = app(WalletService::class)->forOrganization($org->fresh())->fresh();

        // Both, compared: `total_earned` moving with `balance_pending` is what
        // says the category reached the whitelist.
        $this->assertSame('75000.00', $wallet->balance_pending);
        $this->assertSame('75000.00', $wallet->total_earned);
    }

    /**
     * Refunding a still-PENDING credit cancels it in place — no debit row, no
     * negative balance. That is the documented wallet rule, not a special case
     * for races, and the pair below is what separates "cancelled" from
     * "deleted": the row survives, carrying the reason.
     */
    public function test_refunding_a_pending_credit_cancels_it_and_frees_the_place(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000], quota: 5);

        $this->gateway(true);
        $entry = RaceEntry::findOrFail($this->register(User::factory()->create(), $org, $event, $category)['entry']['id']);

        $before = app(WalletService::class)->forOrganization($org->fresh())->fresh();

        app(RefundService::class)->refundRaceEntry($entry, $owner, 'Batal ikut');

        $after = app(WalletService::class)->forOrganization($org->fresh())->fresh();

        $this->assertSame('75000.00', $before->balance_pending);
        $this->assertSame('0.00', $after->balance_pending);
        // total_earned comes back down with it — the other half of the
        // EARNING_CATEGORIES invariant, read from the opposite direction.
        $this->assertSame('0.00', $after->total_earned);

        // One row, cancelled in place rather than deleted: the ledger keeps the
        // record that money was once claimed here. Asserting the balance alone
        // would pass even if the row had been thrown away.
        $rows = $org->fresh()->wallet->transactions()->where('source_id', $entry->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('cancelled', $rows->first()->status);

        $this->assertSame('refunded', $entry->fresh()->status);
        $this->assertSame(0, $category->fresh()->taken);
    }

    /**
     * Once the credit has been RELEASED, the same refund writes a debit instead
     * — and the balance is allowed to go negative, because the organizer may
     * already have withdrawn it.
     *
     * The pair with the test above is the whole point: one refund method, two
     * shapes, and asserting either alone would pass a build that only ever did
     * that one.
     */
    public function test_refunding_a_released_credit_writes_a_debit(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000], quota: 5);

        $this->gateway(true);
        $entry = RaceEntry::findOrFail($this->register(User::factory()->create(), $org, $event, $category)['entry']['id']);

        // Past the 01:00 WIB boundary, so the hourly sweep releases it.
        Carbon::setTestNow('2026-01-06 03:00:00');
        app(WalletService::class)->releaseDue();

        $released = app(WalletService::class)->forOrganization($org->fresh())->fresh();
        $this->assertSame('75000.00', $released->balance_available);

        app(RefundService::class)->refundRaceEntry($entry, $owner, 'Batal ikut');

        $after = app(WalletService::class)->forOrganization($org->fresh())->fresh();

        $this->assertSame('0.00', $after->balance_available);
        // Two rows now: the credit and its reversal.
        $this->assertSame(2, $org->fresh()->wallet->transactions()->where('source_id', $entry->id)->count());
    }

    // ---- documents ----

    /**
     * A paid entry has both documents; a free one has neither — compared in one
     * test, because "the receipt downloads" passes on its own even when a Rp 0
     * entry also issues one.
     */
    public function test_documents_exist_for_a_paid_entry_and_not_for_a_free_one(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);

        $paid = $this->category($event, 'Umum', ['Early Bird' => 75_000]);
        $free = $this->category($event, 'Komunitas', ['Early Bird' => 0]);

        $this->gateway(true);
        $paidId = $this->register(User::factory()->create(), $org, $event, $paid)['entry']['id'];
        $freeId = $this->register(User::factory()->create(), $org, $event, $free)['entry']['id'];

        $this->assertNotNull(RaceEntry::findOrFail($paidId)->receipt_number);
        $this->get("/api/v1/race-entries/{$paidId}/receipt")->assertOk();

        // A Rp 0 invoice claims a transaction that never happened.
        $this->assertNull(RaceEntry::findOrFail($freeId)->invoice_number);
        $this->assertNull(RaceEntry::findOrFail($freeId)->receipt_number);
        $this->get("/api/v1/race-entries/{$freeId}/receipt")->assertNotFound();
    }

    /** Receipt numbers come from the race stream, not the registration one. */
    public function test_documents_use_their_own_numbering_stream(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $this->gateway(true);
        $entry = RaceEntry::findOrFail($this->register(User::factory()->create(), $org, $event, $category)['entry']['id']);

        $this->assertStringStartsWith('INV-RUN/2026/01/', $entry->invoice_number);
        $this->assertStringStartsWith('KW-RUN/2026/01/', $entry->receipt_number);
    }

    // ---- gates ----

    public function test_a_plan_without_online_registration_refuses_with_422(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        // Everything except the one key this door reads.
        $event = $this->raceEvent($org, $this->planWith(['payment_gateway' => 'true']));
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        // 422, not 403: to a runner this is "not open", and a 403 on a public
        // form reads as a permission problem they cannot act on.
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar', 'email' => 'a@test.com', 'payment_channel' => 'va',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.feature', 'online_registration');

        $this->assertSame(0, RaceEntry::count());
    }

    /**
     * The route does not exist for a competition event, and 404 rather than 422
     * is the honest answer: a football event has `event_categories`, and the
     * category id sent here could never belong to it.
     */
    public function test_the_race_door_is_closed_on_a_competition_event(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $race = $this->raceEvent($org);
        $category = $this->category($race, 'Umum', ['Early Bird' => 75_000]);

        $football = $this->eventOn($org, null, [
            'sport_type' => 'football',
            'status' => 'open',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
        ]);

        // Compared against the race event, which accepts the same payload
        // shape: asserting the 404 alone would pass even if the door were shut
        // for everyone.
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$race->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar', 'email' => 'a@test.com', 'payment_channel' => 'va',
            ])
            ->assertCreated();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$football->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Dimas', 'email' => 'd@test.com', 'payment_channel' => 'va',
            ])
            ->assertNotFound();
    }

    public function test_registering_requires_an_account(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
            'race_category_id' => $category->id,
            'full_name' => 'Ammar', 'email' => 'a@test.com', 'payment_channel' => 'va',
        ])->assertUnauthorized();
    }

    /**
     * The runner list is org.admin, not plain tenant: these rows carry an
     * email, a phone and an emergency contact. Three actors in one payload —
     * asserting the owner's 200 alone passes identically under either gate.
     */
    /**
     * `sport_type` is frozen once anything hangs off it, in BOTH directions.
     *
     * Compared as three saves on two events: the empty draft changes freely,
     * and the two events that have rows refuse. Asserting only the fun run
     * would pass a guard that reads `race_entries` alone — and a football event
     * turned into a fun run strands its teams and matches just as silently.
     */
    public function test_sport_type_is_frozen_once_the_event_has_rows(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        // An empty event changes freely — the half that stops this being a
        // blanket refusal.
        $empty = $this->raceEvent($org);
        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$empty->id}", ['sport_type' => 'football'])
            ->assertOk();
        $this->assertSame('football', $empty->fresh()->sport_type);

        // A fun run with a runner in it does not.
        $race = $this->raceEvent($org);
        $category = $this->category($race, 'Umum', ['Early Bird' => 75_000]);
        $this->register(User::factory()->create(), $org, $race, $category);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$race->id}", ['sport_type' => 'football'])
            ->assertStatus(422)
            ->assertJsonPath('errors.sport_type.0', 'Cabang olahraga tidak bisa diubah karena sudah ada peserta, jadwal, atau kategori tiket.');
        $this->assertSame('fun_run', $race->fresh()->sport_type);

        // And the other direction: a football event with a team cannot become
        // a fun run. Same guard, same message, no second rule.
        $football = $this->eventOn($org, null, ['sport_type' => 'football', 'status' => 'open']);
        $ballCategory = $football->categories()->create([
            'name' => 'Open', 'slug' => 'open', 'tournament_format' => 'league',
        ]);
        $football->teams()->create([
            'category_id' => $ballCategory->id, 'name' => 'FC Test', 'status' => 'approved',
        ]);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$football->id}", ['sport_type' => 'fun_run'])
            ->assertStatus(422);
        $this->assertSame('football', $football->fresh()->sport_type);
    }

    /**
     * A ticket category freezes the sport too, and it is the arm that reaches an
     * event with no entrants at all.
     *
     * Compared against a draft that has none: asserting the refusal alone would
     * pass a guard that froze every event, and the empty save above is in a
     * different test on a different event. The tickets are what makes this
     * reachable — `teams`, `matches` and `race_entries` all need somebody to
     * have entered, while a ticket category can be set up the day the event is
     * created. Without this arm a football event could be moved to `fun_run`
     * carrying categories the new sport is refused at
     * (TicketCategoryController::ensureTicketsEnabled), sellable through a door
     * and invisible on every race surface.
     */
    public function test_sport_type_is_frozen_by_a_ticket_category_alone(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $bare = $this->eventOn($org, null, ['sport_type' => 'football', 'status' => 'draft']);
        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$bare->id}", ['sport_type' => 'fun_run'])
            ->assertOk();
        $this->assertSame('fun_run', $bare->fresh()->sport_type);

        $ticketed = $this->eventOn($org, null, ['sport_type' => 'football', 'status' => 'draft']);
        $ticketed->ticketCategories()->create([
            'name' => 'Tribun', 'price' => 50_000, 'quota' => 100, 'is_active' => true,
        ]);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$ticketed->id}", ['sport_type' => 'fun_run'])
            ->assertStatus(422);
        $this->assertSame('football', $ticketed->fresh()->sport_type);
    }

    /**
     * A fun run sells no spectator ticket, and the refusal is a 422 about the
     * sport rather than the plan's 403.
     *
     * Compared against a football event on the SAME plan, so the plan cannot
     * explain the difference — the pattern every gate in this codebase is tested
     * with. Asserting the race's refusal alone would pass a build that refused
     * ticket categories outright, which is ten sports losing the feature.
     *
     * The status codes are part of what is asserted, not incidental. 403 with
     * `errors.feature` is the plan door, which isPlanLimitError() turns into a
     * "upgrade your plan" toast; sending that for a fun run would point the
     * organizer at a purchase that cannot change the answer. Hence a plain 422
     * with no `feature` key.
     */
    public function test_a_fun_run_cannot_create_a_spectator_ticket_category(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $payload = ['name' => 'Tribun', 'price' => 50_000, 'quota' => 100, 'is_active' => true];

        // The same plan runs both events, so only the sport differs.
        $plan = $this->fullPlan();

        $race = $this->raceEvent($org, $plan);
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$race->id}/ticket-categories", $payload)
            ->assertStatus(422)
            // Not the plan's shape: `errors.feature` here would be swallowed
            // into a toast about an upgrade that cannot help.
            ->assertJsonMissingPath('errors.feature');
        $this->assertSame(0, $race->ticketCategories()->count());

        $football = $this->eventOn($org, $plan, ['sport_type' => 'football', 'status' => 'open']);
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$football->id}/ticket-categories", $payload)
            ->assertCreated();
        $this->assertSame(1, $football->ticketCategories()->count());
    }

    /**
     * Posting the same sport back is never a change, so a form that round-trips
     * the field does not lock an organizer out of renaming their own event —
     * the same reason `payment_method` is guarded by array_key_exists.
     */
    public function test_resaving_the_same_sport_is_allowed_with_rows_present(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);
        $this->register(User::factory()->create(), $org, $event, $category);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$event->id}", [
                'name' => 'Fun Run Kampus 2026',
                'sport_type' => 'fun_run',
            ])
            ->assertOk();

        $this->assertSame('Fun Run Kampus 2026', $event->fresh()->name);
    }

    /**
     * The channel picker's preview endpoint has to accept the race audience.
     *
     * Compared against a rejected word, because a silent refusal here is the
     * worst shape of this bug: the picker fetches no channels, renders nothing,
     * and the runner is left on a form that cannot be submitted with no field
     * to look at — exactly what a too-tight `units` cap once did to a 101-seat
     * ticket order. Comparing the two also proves the rule still refuses an
     * audience the `match` below it could not map.
     */
    /**
     * `POST events` demands at least one category — except for a sport that has
     * none to give.
     *
     * Found by running the real app rather than by reading: the create request
     * spreads `EventCategoryRules::make('required')`, which cannot see the
     * sport, so a fun run was impossible to create at all. Compared as two
     * creates in one test: asserting the race one succeeds would pass even if
     * the rule had been loosened for every sport, which would let a football
     * event be created with nothing to enter.
     */
    public function test_a_race_event_is_created_without_categories_but_a_competition_one_is_not(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $this->creditFor($org);
        $this->creditFor($org);

        $base = [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
            'timezone' => 'Asia/Jakarta',
        ];

        $race = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                ...$base,
                'name' => 'Fun Run Kampus',
                'sport_type' => 'fun_run',
            ])
            ->assertCreated()
            ->json('data');

        // No competition categories, and none invented on its behalf.
        $this->assertSame([], $race['categories']);

        // The binding half: a sport that plays matches still cannot be created
        // without one.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                ...$base,
                'name' => 'Liga Futsal',
                'sport_type' => 'futsal',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('categories');
    }

    /**
     * Updating a race event never touches `event_categories`, and a competition
     * event's are still synced.
     *
     * The pair is what pins `syncCategories()`'s early return: without it the
     * delete sweep at the end of that method runs with an empty `$keep` and
     * drops every category the event had.
     */
    public function test_updating_a_race_event_leaves_competition_categories_alone(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);

        $race = $this->raceEvent($org);
        $this->category($race, 'Umum', ['Early Bird' => 75_000]);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$race->id}", [
                'name' => 'Fun Run Kampus 2026',
            ])
            ->assertOk();

        // The race categories are a different table and survive untouched.
        $this->assertSame(1, $race->raceCategories()->count());
        $this->assertSame(0, $race->categories()->count());

        // And a competition event's categories still sync, which is what says
        // the early return is keyed on the sport and not simply always taken.
        $football = $this->eventOn($org, null, ['sport_type' => 'football', 'status' => 'open']);
        $football->categories()->create([
            'name' => 'Open', 'slug' => 'open', 'tournament_format' => 'league',
        ]);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$football->id}", [
                'categories' => [
                    ['name' => 'U-17', 'tournament_format' => 'league'],
                ],
            ])
            ->assertOk();

        $this->assertSame(['U-17'], $football->categories()->pluck('name')->all());
    }

    public function test_the_fee_preview_accepts_the_race_audience(): void
    {
        $this->getJson('/api/v1/public/payment-channels?amount=75000&audience=race_entry')
            ->assertOk()
            ->assertJsonPath('data.0.channel', 'va');

        $this->getJson('/api/v1/public/payment-channels?amount=75000&audience=fun_run')
            ->assertStatus(422);
    }

    /**
     * The runner pays the race margin, not the team-registration one.
     *
     * Compared as two entries under two different settings: asserting a single
     * `service_fee` would pass even if the flow read another audience's rate,
     * because in a one-step test the two settings are both zero by default.
     */
    public function test_the_race_entry_carries_its_own_service_fee(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $this->gateway(true);
        PlatformSettings::put([
            'race_entry_service_fee_amount' => 5_000,
            'registration_service_fee_amount' => 25_000,
        ], null);
        PlatformSettings::flush();

        $entry = RaceEntry::findOrFail(
            $this->register(User::factory()->create(), $org, $event, $category)['entry']['id'],
        );

        // 5.000, not 25.000: the two rates are set apart precisely so one flow
        // cannot inherit the other's.
        $this->assertSame('5000.00', $entry->service_fee);
        $this->assertSame('75000.00', $entry->price);
    }

    public function test_the_runner_list_is_owner_admin_only(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $outsider = User::factory()->create();

        $org = $this->orgFor($owner);
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $event = $this->raceEvent($org);
        $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/race-entries";

        $this->actingAs($owner, 'api')->getJson($url)->assertOk();
        $this->actingAs($operator, 'api')->getJson($url)->assertForbidden();
        $this->actingAs($outsider, 'api')->getJson($url)->assertForbidden();
    }

    /**
     * Invarian: `paid` dan `awaiting_verification` dihitung EVENT-WIDE, bukan
     * dari baris yang kebetulan ada di halaman ini.
     *
     * Dibandingkan dengan **meminta dua halaman** dari daftar yang sama: angka
     * keduanya harus identik. Assert "paid = 2" pada satu halaman akan lolos
     * penuh walau hitungannya diambil dari `items` — di halaman pertama yang
     * memuat semuanya, kedua cara menjawab sama. Itulah bentuk bug yang
     * sebelumnya ada: benar selama seluruh daftar termuat sekaligus, lalu
     * berbunyi "120 pendaftar · 25 lunas" begitu dipaginasi.
     */
    public function test_the_headline_counts_are_event_wide_not_page_wide(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        foreach (range(1, 3) as $i) {
            $entry = RaceEntry::findOrFail(
                $this->register(User::factory()->create(), $org, $event, $category)['entry']['id'],
            );

            // With no Midtrans server key the suite settles an entry on the
            // spot, so one is pushed BACK to pending rather than two forward to
            // paid. The point is only that `paid` differs from `total` — if the
            // two matched, a page-wide count would answer correctly by luck.
            if ($i === 3) {
                $entry->update(['status' => 'pending', 'paid_at' => null]);
            }
        }

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/race-entries";

        $first = $this->actingAs($owner, 'api')->getJson($url.'?per_page=2')->json('data');
        $second = $this->actingAs($owner, 'api')->getJson($url.'?per_page=2&page=2')->json('data');

        // The pages really are different sizes — otherwise the comparison below
        // would be two reads of the same thing.
        $this->assertCount(2, $first['items']);
        $this->assertCount(1, $second['items']);

        foreach ([$first, $second] as $page) {
            $this->assertSame(3, $page['meta']['total']);
            $this->assertSame(2, $page['meta']['paid']);
        }
    }

    /**
     * Dan angka yang sama menyempit saat daftarnya disaring: ia dihitung dari
     * query yang SAMA dengan yang melahirkan halamannya.
     *
     * Dibandingkan dengan hitungan tanpa filter di test yang sama — assert
     * "paid = 1" sendirian lolos walau filternya tidak pernah sampai ke
     * hitungan.
     */
    public function test_the_counts_follow_the_filter(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        foreach (['Ammar Wijaya', 'Dimas Prasetyo'] as $name) {
            $entry = RaceEntry::findOrFail(
                $this->register(User::factory()->create(), $org, $event, $category, [
                    'full_name' => $name,
                ])['entry']['id'],
            );
            app(\App\Services\RaceEntryService::class)->markPaid($entry);
        }

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/race-entries";

        $all = $this->actingAs($owner, 'api')->getJson($url)->json('data.meta');
        $one = $this->actingAs($owner, 'api')->getJson($url.'?search=Dimas')->json('data.meta');

        $this->assertSame(2, $all['total']);
        $this->assertSame(2, $all['paid']);
        $this->assertSame(1, $one['total']);
        $this->assertSame(1, $one['paid']);
    }

    /**
     * Invarian: kertas PDF melebar mengikuti jumlah kolom.
     *
     * Dibandingkan dengan ekspor yang kolomnya sedikit, karena itu yang
     * membedakan "kertas dihitung" dari "kertas dipaku landscape": assert
     * "lebarnya > A4" sendirian lolos walau setiap ekspor dapat lebar yang
     * sama. Ini bug yang sudah terjadi — tanpa `setPaper` dompdf memakai A4
     * potret dan memotong kolom kedelapan ke atas tanpa suara.
     */
    public function test_pdf_paper_widens_with_the_column_count(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $this->category($event, 'Umum', ['Early Bird' => 75_000]);

        $event->update(['registration_form' => [
            'team_fields' => array_map(fn ($i) => [
                'key' => "f{$i}", 'label' => "Field {$i}", 'type' => 'short_text',
                'required' => false, 'options' => [],
            ], range(1, 12)),
        ]]);

        $wide = new \App\Exports\RaceEntriesExport($event->fresh());
        $narrow = new \App\Exports\TicketBuyersExport($event->fresh());

        $this->assertGreaterThan(
            count($narrow->headings()),
            count($wide->headings()),
            'Fixture salah: ekspor pembandingnya harus lebih sempit.',
        );

        // The pair that binds it: a wider table gets wider paper, and the
        // narrow one is not simply given the same sheet.
        $this->assertGreaterThan($narrow->paper()[2], $wide->paper()[2]);

        // And the floor holds — a narrow export still prints on ordinary paper
        // rather than on something too small to read.
        $this->assertGreaterThanOrEqual(595.28, $narrow->paper()[2]);
    }
}
