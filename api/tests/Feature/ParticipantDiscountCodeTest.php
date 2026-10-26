<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventDiscountCode;
use App\Models\Organization;
use App\Models\RaceCategory;
use App\Models\Team;
use App\Models\User;
use App\Services\EventDiscountCodeService;
use App\Services\ParticipantDocumentService;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Discount codes a participant types while registering — both rails.
 *
 * Every case here **compares** two runs rather than asserting one, which is the
 * house rule this suite inherits from PlanDiscountCodeTest: a single "the
 * discount applied" assert passes just as happily on a build that applies the
 * same wrong number to everything, or that discounts after the fee was
 * computed, or that never counts the second table.
 *
 * Midtrans is unconfigured under test, so a gateway registration settles on the
 * spot — which is what makes the quota and once-per-user cases readable: the
 * first registration is genuinely paid.
 */
class ParticipantDiscountCodeTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::flush();
        Mail::fake();

        // Inside Early Bird on the race event below. Pinned because half of
        // what is asserted here is an amount, and a wall clock would make the
        // suite say something different every month.
        Carbon::setTestNow('2026-01-05 03:00:00'); // 10:00 WIB
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----

    /** A competition event with one priced category. */
    private function teamEvent(Organization $org, float $fee = 200_000): Event
    {
        $event = $this->eventOn($org, null, [
            'status' => 'open',
            'registration_open' => '2026-01-01',
            'registration_close' => '2026-02-28',
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => $fee, 'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

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

    private function raceCategory(Event $event, float $price = 100_000, ?int $quota = null): RaceCategory
    {
        $category = $event->raceCategories()->create([
            'name' => 'Umum', 'slug' => Str::slug('Umum-'.uniqid()), 'quota' => $quota,
        ]);

        $category->prices()->create([
            'race_period_id' => $event->racePeriods()->firstOrFail()->id,
            'price' => $price,
        ]);

        return $category;
    }

    /**
     * One event both rails can reach: a `fun_run` carrying an
     * `event_categories` row as well as a `race_categories` one.
     *
     * The shape looks odd on purpose, and it is the only shape that can prove
     * what these two cases are about. A code belongs to an event, and the
     * public race route 404s on any sport that plays fixtures — so on an
     * ordinary football event the runner rail is unreachable and a single code
     * can only ever be seen by one of usage()'s two queries. Nothing refuses
     * competition categories on a race event (only the reverse is guarded), so
     * this is reachable rather than contrived.
     *
     * @return array{0: Event, 1: RaceCategory}
     */
    private function dualEvent(Organization $org, float $fee = 200_000, float $price = 100_000): array
    {
        $event = $this->raceEvent($org);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => $fee, 'sort_order' => 0,
        ]);

        $category = $this->raceCategory($event, $price);

        return [$event->fresh()->load('categories'), $category];
    }

    /** @param array<string, mixed> $attrs */
    private function code(Event $event, array $attrs = []): EventDiscountCode
    {
        return $event->discountCodes()->create([
            'code' => 'CODE'.strtoupper(bin2hex(random_bytes(3))),
            'discount_type' => 'percent',
            'discount_value' => 25,
            ...$attrs,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>  the payload on success, the whole envelope on a refusal
     */
    private function registerTeam(User $manager, Event $event, ?string $code = null, int $status = 201, string $channel = 'va', array $overrides = []): array
    {
        $org = $event->organization;

        $response = $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'payment_channel' => $channel,
                'discount_code' => $code,
                ...$overrides,
            ])
            ->assertStatus($status);

        return $status === 201 ? $response->json('data') : $response->json();
    }

    /**
     * @return array<string, mixed>  the payload on success, the whole envelope on a refusal
     */
    private function registerRunner(User $runner, Event $event, RaceCategory $category, ?string $code = null, int $status = 201, string $channel = 'va'): array
    {
        $org = $event->organization;

        $response = $this->actingAs($runner, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/race/register", [
                'race_category_id' => $category->id,
                'full_name' => 'Ammar',
                'email' => 'ammar'.uniqid().'@test.com',
                'phone' => '08123456789',
                'payment_channel' => $channel,
                'discount_code' => $code,
            ])
            ->assertStatus($status);

        return $status === 201 ? $response->json('data') : $response->json();
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    // ---- the fee invariant ----

    /**
     * The discount lands BEFORE the fee is computed.
     *
     * On the `ewallet` channel the gateway fee is a percentage, so a build that
     * discounts afterwards leaves it quoting a fee on a price nobody is
     * charged. The comparison is the whole test: `va` is flat, so the same two
     * registrations there would look identical on a correct build and a broken
     * one.
     */
    public function test_the_gateway_fee_follows_the_discounted_price_on_a_percentage_channel(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);
        $code = $this->code($event, ['discount_value' => 50, 'once_per_user' => false]);

        $full = $this->registerTeam(User::factory()->create(), $event, null, channel: 'ewallet');
        $discounted = $this->registerTeam(User::factory()->create(), $event, $code->code, channel: 'ewallet');

        $fullTeam = Team::findOrFail($full['team']['id']);
        $cutTeam = Team::findOrFail($discounted['team']['id']);

        $this->assertSame(200_000.0, $this->money($fullTeam->payment_amount));
        $this->assertSame(100_000.0, $this->money($cutTeam->payment_amount));

        // Half the price, so half the percentage fee — not the full one.
        $this->assertSame(
            $this->money((float) $fullTeam->gateway_fee / 2),
            $this->money($cutTeam->gateway_fee),
        );
        $this->assertLessThan($this->money($fullTeam->gateway_fee), $this->money($cutTeam->gateway_fee));
    }

    /**
     * The organizer bears the discount: the wallet is credited the net.
     *
     * Compared against a full-price registration in the same category, because
     * "the credit equals payment_amount" is true on a build that never applied
     * the discount at all.
     */
    public function test_the_wallet_is_credited_the_net_price_not_the_catalogue_one(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);
        $code = $this->code($event, ['discount_value' => 25, 'once_per_user' => false]);

        $full = $this->registerTeam(User::factory()->create(), $event);
        $discounted = $this->registerTeam(User::factory()->create(), $event, $code->code);

        $fullCredit = $org->wallet->transactions()
            ->where('source_id', $full['team']['id'])->firstOrFail();
        $cutCredit = $org->wallet->transactions()
            ->where('source_id', $discounted['team']['id'])->firstOrFail();

        $this->assertSame(200_000.0, $this->money($fullCredit->amount));
        $this->assertSame(150_000.0, $this->money($cutCredit->amount));
    }

    // ---- the snapshot ----

    /**
     * Editing and then deleting the code leaves an issued bill untouched.
     *
     * This is why EventDiscountCodeController carries no "still in use" guard,
     * unlike FeatureDefinitionController: nothing reads the row back.
     */
    public function test_the_order_keeps_its_snapshot_after_the_code_is_edited_and_deleted(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);
        $code = $this->code($event, ['discount_value' => 25]);

        $registered = $this->registerTeam(User::factory()->create(), $event, $code->code);
        $team = Team::findOrFail($registered['team']['id']);

        $before = [
            $team->discount_code,
            $team->discount_type,
            $this->money($team->discount_value),
            $this->money($team->discount_amount),
            $this->money($team->payment_amount),
        ];

        $code->update(['code' => 'RENAMED', 'discount_value' => 90]);
        $code->delete();

        $after = $team->fresh();

        $this->assertSame($before, [
            $after->discount_code,
            $after->discount_type,
            $this->money($after->discount_value),
            $this->money($after->discount_amount),
            $this->money($after->payment_amount),
        ]);
        // Only the FK goes null — nullOnDelete, by design.
        $this->assertNull($after->event_discount_code_id);
    }

    /**
     * The document prints the catalogue price with the discount beneath it.
     *
     * Asserted on the HTML, not the PDF bytes: dompdf output is compressed
     * streams, so a test holding the bytes can only say that *something*
     * rendered — and that stays green when the row disappears.
     */
    public function test_the_invoice_prints_the_catalogue_price_and_the_discount_row(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);
        $code = $this->code($event, ['discount_value' => 25, 'once_per_user' => false]);

        $full = Team::findOrFail($this->registerTeam(User::factory()->create(), $event)['team']['id']);
        $cut = Team::findOrFail($this->registerTeam(User::factory()->create(), $event, $code->code)['team']['id']);

        $documents = app(ParticipantDocumentService::class);

        $fullHtml = $documents->html('receipt', $full);
        $cutHtml = $documents->html('receipt', $cut);

        // The item row always carries the catalogue price, discounted or not —
        // that is what the discount row is a discount *off*.
        $this->assertStringContainsString('Rp 200.000', $fullHtml);
        $this->assertStringContainsString('Rp 200.000', $cutHtml);

        // And only the discounted one carries the row — the amount taken off,
        // not the net, because the net is already the Total line below it.
        $this->assertStringContainsString('Diskon '.$code->code, $cutHtml);
        $this->assertStringNotContainsString('Diskon ', $fullHtml);
        $this->assertStringContainsString('Rp 50.000', $cutHtml);
        $this->assertStringNotContainsString('Rp 50.000', $fullHtml);

        // The totals are the rows' own arithmetic, net of the discount and
        // inclusive of the fees — asserted against the stored gross rather
        // than a literal, which is what keeps this from re-stating the fee
        // config.
        $rupiah = fn (float $n) => 'Rp '.number_format($n, 0, ',', '.');
        $this->assertStringContainsString($rupiah($cut->gross_amount), $cutHtml);
        $this->assertStringContainsString($rupiah($full->gross_amount), $fullHtml);
        $this->assertLessThan($full->gross_amount, $cut->gross_amount);
    }

    // ---- quota ----

    /**
     * An unpaid registration reserves a quota slot, and releasing it gives the
     * slot back.
     *
     * Without `reserved` in the gate, fifty simultaneous registrations all pass
     * a quota of one and every one of them is then payable — so the first half
     * of this pair is what proves the reservation exists, and the second that
     * it is not permanent.
     */
    public function test_an_unpaid_registration_holds_the_quota_and_withdrawing_releases_it(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        $category = $this->raceCategory($event, 100_000);
        // Gateway off → the entry stays pending with a manual transfer to make,
        // which is a reservation rather than a redemption.
        PlatformSettings::put(['payment_gateway_enabled' => false], null);
        PlatformSettings::flush();
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'bank_code' => '014', 'account_number' => '1234567890',
            'account_holder' => 'EO', 'is_primary' => true,
        ]);

        $code = $this->code($event, ['max_redemptions' => 1, 'once_per_user' => false]);

        $first = $this->registerRunner(User::factory()->create(), $event, $category, $code->code);
        $entry = \App\Models\RaceEntry::findOrFail($first['entry']['id']);
        $this->assertSame('pending', $entry->status);

        $service = app(EventDiscountCodeService::class);
        $this->assertSame(['redeemed' => 0, 'reserved' => 1], $service->usage($code));

        // Quota of one, already reserved.
        $refused = $this->registerRunner(User::factory()->create(), $event, $category, $code->code, status: 422);
        $this->assertSame(
            'Kuota pemakaian kode ini sudah habis.',
            $refused['errors']['discount_code'][0] ?? null,
        );

        app(\App\Services\RaceEntryService::class)->cancel($entry);

        $this->assertSame(['redeemed' => 0, 'reserved' => 0], $service->usage($code->fresh()));
        $this->registerRunner(User::factory()->create(), $event, $category, $code->code);
    }

    /**
     * One code, both rails, one tally.
     *
     * usage() runs two queries and folds them once; asserting either rail alone
     * stays green when the other query returns nothing — which is exactly half
     * of what this feature adds.
     */
    public function test_usage_counts_both_rails_not_just_one(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        // One event, both kinds of category — see dualEvent() for why that is
        // the only fixture that can show the fold.
        [$event, $raceCategory] = $this->dualEvent($org);

        $code = $this->code($event, ['discount_value' => 10, 'once_per_user' => false]);
        $service = app(EventDiscountCodeService::class);

        $this->registerTeam(User::factory()->create(), $event, $code->code);
        $this->assertSame(1, $service->usage($code)['redeemed']);

        $this->registerRunner(User::factory()->create(), $event->fresh(), $raceCategory, $code->code);
        $this->assertSame(2, $service->usage($code)['redeemed']);
    }

    // ---- scope ----

    /**
     * `once_per_user` is per account, not per code.
     *
     * The second account is the comparison: asserting only the refusal passes
     * on a build where the code works exactly once in total.
     */
    public function test_once_per_user_refuses_the_same_account_and_admits_another(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);
        $code = $this->code($event, ['once_per_user' => true]);

        $manager = User::factory()->create();
        $this->registerTeam($manager, $event, $code->code);

        $refused = $this->registerTeam($manager, $event, $code->code, status: 422);
        $this->assertSame(
            'Kamu sudah memakai kode ini untuk event ini.',
            $refused['errors']['discount_code'][0] ?? null,
        );

        $this->registerTeam(User::factory()->create(), $event, $code->code);
    }

    /**
     * A code belongs to ONE event.
     *
     * Both directions, because asserting only that event A's code is refused on
     * B passes on a build where every code is refused everywhere.
     */
    public function test_a_code_is_refused_on_another_event_of_the_same_org(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $eventA = $this->teamEvent($org, 200_000);
        $eventB = $this->teamEvent($org, 200_000);

        $codeA = $this->code($eventA, ['once_per_user' => false]);
        $codeB = $this->code($eventB, ['once_per_user' => false]);

        $refused = $this->registerTeam(User::factory()->create(), $eventB, $codeA->code, status: 422);
        $this->assertArrayHasKey('discount_code', $refused['errors']);

        $accepted = $this->registerTeam(User::factory()->create(), $eventB, $codeB->code);
        $this->assertSame(150_000.0, $this->money($accepted['team']['payment_amount']));
    }

    // ---- retry ----

    /**
     * Reopening payment keeps the stored snapshot and spends no second slot.
     *
     * Compared against a team that had no code and sends one on its first
     * attempt, which DOES take effect — otherwise this passes on a build where
     * the code is simply never read by `pay()`.
     */
    public function test_a_retry_keeps_the_stored_discount_and_a_first_attempt_applies_one(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->raceEvent($org);
        // Manual rail so the registration stays unpaid and `pay()` is reachable.
        PlatformSettings::put(['payment_gateway_enabled' => false], null);
        PlatformSettings::flush();
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'bank_code' => '014', 'account_number' => '1234567890',
            'account_holder' => 'EO', 'is_primary' => true,
        ]);

        $teamEvent = $this->teamEvent($org, 200_000);
        $first = $this->code($teamEvent, ['discount_value' => 25, 'once_per_user' => false]);
        $second = $this->code($teamEvent, ['discount_value' => 90, 'once_per_user' => false]);

        $manager = User::factory()->create();
        $registered = $this->registerTeam($manager, $teamEvent, $first->code);
        $team = Team::findOrFail($registered['team']['id']);

        $this->assertSame('unpaid', $team->payment_status);
        $this->assertSame(150_000.0, $this->money($team->payment_amount));

        // A retry sending a *better* code changes nothing: same bill.
        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/my-teams/{$team->id}/pay", ['discount_code' => $second->code])
            ->assertOk();

        $retried = $team->fresh();
        $this->assertSame($first->code, $retried->discount_code);
        $this->assertSame(150_000.0, $this->money($retried->payment_amount));
        // The better code was never resolved, so it spent nothing at all.
        $this->assertSame(
            ['redeemed' => 0, 'reserved' => 0],
            app(EventDiscountCodeService::class)->usage($second),
        );
        $this->assertSame(
            ['redeemed' => 0, 'reserved' => 1],
            app(EventDiscountCodeService::class)->usage($first),
        );

        // The comparison: a team with no snapshot yet, sending the same code on
        // its first `pay()`, does get it.
        $plain = $this->registerTeam(User::factory()->create(), $teamEvent);
        $plainTeam = Team::findOrFail($plain['team']['id']);
        $this->assertNull($plainTeam->discount_code);

        $this->actingAs($plainTeam->manager, 'api')
            ->postJson("/api/v1/my-teams/{$plainTeam->id}/pay", ['discount_code' => $second->code])
            ->assertOk();

        $this->assertSame($second->code, $plainTeam->fresh()->discount_code);
        $this->assertSame(20_000.0, $this->money($plainTeam->fresh()->payment_amount));
    }

    // ---- preview ----

    /**
     * The preview quotes the amount the till charges, on both rails.
     *
     * One resolver, so a code can never be accepted here and refused at
     * checkout — the reason the endpoint exists at all.
     */
    public function test_the_preview_quotes_the_same_amount_the_registration_charges(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        [$event, $raceCategory] = $this->dualEvent($org);
        $code = $this->code($event, ['discount_value' => 25, 'once_per_user' => false]);

        $url = "/api/v1/public/events/{$org->slug}/{$event->slug}/discount-preview";

        $teamPreview = $this->actingAs(User::factory()->create(), 'api')
            ->postJson($url, [
                'discount_code' => $code->code,
                'category_id' => $event->categories->first()->id,
            ])->assertOk()->json('data');

        $racePreview = $this->actingAs(User::factory()->create(), 'api')
            ->postJson($url, [
                'discount_code' => $code->code,
                'race_category_id' => $raceCategory->id,
            ])->assertOk()->json('data');

        // Same code, two prices — so the preview is reading the category, not a
        // single hard-wired amount.
        $this->assertSame([200_000.0, 150_000.0], [$this->money($teamPreview['price']), $this->money($teamPreview['amount'])]);
        $this->assertSame([100_000.0, 75_000.0], [$this->money($racePreview['price']), $this->money($racePreview['amount'])]);

        // And each matches what the till actually charges.
        $team = $this->registerTeam(User::factory()->create(), $event, $code->code);
        $this->assertSame($this->money($teamPreview['amount']), $this->money($team['team']['payment_amount']));

        $entry = $this->registerRunner(User::factory()->create(), $event->fresh(), $raceCategory, $code->code);
        $this->assertSame($this->money($racePreview['amount']), $this->money($entry['entry']['price']));
    }

    /**
     * A refusal the preview makes is a 422 on the field, never a feature error.
     *
     * With `errors.feature` present, isPlanLimitError() swallows it into a
     * toast and the input never turns red — the lesson already written for the
     * plan discount and for `payment_method`.
     */
    public function test_a_refused_code_is_a_field_error_and_carries_no_feature_key(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);
        $live = $this->code($event);
        $dead = $this->code($event, ['is_active' => false]);

        $url = "/api/v1/public/events/{$org->slug}/{$event->slug}/discount-preview";
        $category = $event->categories->first()->id;

        $refused = $this->actingAs(User::factory()->create(), 'api')
            ->postJson($url, ['discount_code' => $dead->code, 'category_id' => $category])
            ->assertStatus(422)
            ->json();

        $this->assertArrayHasKey('discount_code', $refused['errors']);
        $this->assertArrayNotHasKey('feature', $refused['errors']);

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson($url, ['discount_code' => $live->code, 'category_id' => $category])
            ->assertOk();
    }

    // ---- edges ----

    /**
     * A full discount settles the registration on the spot, and a partial one
     * does not.
     *
     * The pair is the point: "it settled" is also true of every free category,
     * and `$amount <= 0` was already the branch that settles those.
     */
    public function test_a_full_discount_settles_outright_while_a_partial_one_still_charges(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);
        // Gateway off, so a bill that is still payable stays unpaid — otherwise
        // the mock settles both and the comparison says nothing.
        PlatformSettings::put(['payment_gateway_enabled' => false], null);
        PlatformSettings::flush();
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'bank_code' => '014', 'account_number' => '1234567890',
            'account_holder' => 'EO', 'is_primary' => true,
        ]);

        $whole = $this->code($event, ['discount_value' => 100, 'once_per_user' => false]);
        $half = $this->code($event, ['discount_value' => 50, 'once_per_user' => false]);

        $free = Team::findOrFail($this->registerTeam(User::factory()->create(), $event, $whole->code)['team']['id']);
        $part = Team::findOrFail($this->registerTeam(User::factory()->create(), $event, $half->code)['team']['id']);

        $this->assertSame('paid', $free->payment_status);
        $this->assertSame(0.0, $this->money($free->payment_amount));
        // The snapshot is written even on the settle-outright path, so the code
        // records its use — and no document is issued for a Rp 0 bill.
        $this->assertSame($whole->code, $free->discount_code);
        $this->assertSame(200_000.0, $this->money($free->discount_amount));
        $this->assertNull($free->invoice_number);
        $this->assertSame(1, app(EventDiscountCodeService::class)->usage($whole)['redeemed']);

        $this->assertSame('unpaid', $part->payment_status);
        $this->assertSame(100_000.0, $this->money($part->payment_amount));
        $this->assertNotNull($part->invoice_number);
    }

    /**
     * A free category refuses a code, and registers fine without one.
     *
     * Silently ignoring it would render "diskon diterapkan" above a price of
     * Rp 0 — a discount the participant can no longer find.
     */
    public function test_a_free_category_refuses_a_code_but_still_accepts_a_plain_registration(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 0);
        $code = $this->code($event);

        $refused = $this->registerTeam(User::factory()->create(), $event, $code->code, status: 422);
        $this->assertSame(
            'Pendaftaran ini gratis, jadi kode diskon tidak diperlukan.',
            $refused['errors']['discount_code'][0] ?? null,
        );

        $this->registerTeam(User::factory()->create(), $event);
    }

    /**
     * A percent code's ceiling is honoured; the same code without one is not
     * capped.
     *
     * The uncapped run is the comparison: asserting only the capped amount
     * passes on a build that always applies `max_discount` as the discount.
     */
    public function test_max_discount_caps_a_percent_code_and_its_absence_does_not(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);

        $capped = $this->code($event, ['discount_value' => 50, 'max_discount' => 30_000, 'once_per_user' => false]);
        $uncapped = $this->code($event, ['discount_value' => 50, 'once_per_user' => false]);

        $a = $this->registerTeam(User::factory()->create(), $event, $capped->code);
        $b = $this->registerTeam(User::factory()->create(), $event, $uncapped->code);

        $this->assertSame(170_000.0, $this->money($a['team']['payment_amount']));
        $this->assertSame(100_000.0, $this->money($b['team']['payment_amount']));
    }

    // ---- the organizer's CRUD ----

    /**
     * Three actors, one payload: owner and `operator` may write, an outsider
     * may not.
     *
     * Asserting the owner alone passes identically under `org.admin`, which is
     * the middleware this route deliberately does NOT use — a price list is not
     * a money door.
     */
    public function test_an_operator_may_manage_codes_and_an_outsider_may_not(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator', 'invited_by' => $owner->id]);
        $outsider = User::factory()->create();

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/discount-codes";
        $payload = fn (string $code) => [
            'code' => $code, 'discount_type' => 'percent', 'discount_value' => 10,
        ];

        $this->actingAs($owner, 'api')->postJson($url, $payload('OWNER10'))->assertCreated();
        $this->actingAs($operator, 'api')->postJson($url, $payload('OP10'))->assertCreated();
        $this->actingAs($outsider, 'api')->postJson($url, $payload('OUT10'))->assertForbidden();

        $this->assertSame(2, $event->discountCodes()->count());
    }

    /**
     * The code is lower-cased on the way in and still matches, and the unique
     * check is scoped to the event rather than global.
     *
     * The second half is the comparison: a global unique index would refuse the
     * other event's identical code, which is a code two organizers are both
     * entitled to run.
     */
    public function test_codes_are_case_insensitive_and_unique_only_within_their_event(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $eventA = $this->teamEvent($org, 200_000);
        $eventB = $this->teamEvent($org, 200_000);

        $url = fn (Event $e) => "/api/v1/organizations/{$org->id}/events/{$e->id}/discount-codes";
        $payload = ['code' => 'earlybird', 'discount_type' => 'percent', 'discount_value' => 20];

        $created = $this->actingAs($owner, 'api')->postJson($url($eventA), $payload)
            ->assertCreated()->json('data');
        $this->assertSame('EARLYBIRD', $created['code']);

        // Same event, same code — refused.
        $this->actingAs($owner, 'api')->postJson($url($eventA), $payload)->assertStatus(422);
        // Other event, same code — allowed.
        $this->actingAs($owner, 'api')->postJson($url($eventB), $payload)->assertCreated();

        // And typing it in lower case at registration still matches.
        $applied = $this->registerTeam(User::factory()->create(), $eventA, 'earlybird');
        $this->assertSame(160_000.0, $this->money($applied['team']['payment_amount']));
    }

    /**
     * The organizer's list shows redeemed and reserved apart, and both move.
     *
     * Read through the same service the gate uses, so the number on screen
     * cannot disagree with the number that refuses a participant.
     */
    public function test_the_listing_reports_redeemed_and_reserved_separately(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);
        $code = $this->code($event, ['once_per_user' => false]);

        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/discount-codes";

        $before = $this->actingAs($owner, 'api')->getJson($url)->assertOk()->json('data.0');
        $this->assertSame([0, 0], [$before['redeemed_count'], $before['reserved_count']]);

        // Mock gateway settles it, so this one is a redemption, not a hold.
        $this->registerTeam(User::factory()->create(), $event, $code->code);

        $after = $this->actingAs($owner, 'api')->getJson($url)->assertOk()->json('data.0');
        $this->assertSame([1, 0], [$after['redeemed_count'], $after['reserved_count']]);
    }

    /**
     * An inactive code stays in the organizer's listing.
     *
     * The participant-facing refusal is the comparison: the row is visible to
     * its owner precisely so it can be switched back on, while being unusable.
     * Same split as /plans and /testimonials.
     */
    public function test_an_inactive_code_is_still_listed_but_refused_at_registration(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);
        $code = $this->code($event, ['is_active' => false]);

        $listed = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/discount-codes")
            ->assertOk()->json('data');

        $this->assertCount(1, $listed);
        $this->assertFalse($listed[0]['is_active']);

        $this->registerTeam(User::factory()->create(), $event, $code->code, status: 422);
    }

    /**
     * A code outside its window is refused, and the same code inside it is not.
     *
     * Pinned clock, so the pair is the window rather than the wall.
     */
    public function test_a_code_is_refused_outside_its_window_and_accepted_inside_it(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->teamEvent($org, 200_000);

        $expired = $this->code($event, [
            'ends_at' => Carbon::now()->subDay(), 'once_per_user' => false,
        ]);
        $live = $this->code($event, [
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->addDay(),
            'once_per_user' => false,
        ]);

        $refused = $this->registerTeam(User::factory()->create(), $event, $expired->code, status: 422);
        $this->assertStringContainsString('kedaluwarsa', $refused['errors']['discount_code'][0]);

        $accepted = $this->registerTeam(User::factory()->create(), $event, $live->code);
        $this->assertSame(150_000.0, $this->money($accepted['team']['payment_amount']));
    }
}
