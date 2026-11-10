<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

class MyTeamTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function openEvent(float $fee = 0): Event
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'price' => 0]);
        $plan->features()->create(['feature_key' => 'max_teams_per_category', 'value' => '10']);
        $org = Organization::create(['name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id]);

        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Cup', 'slug' => 'cup', 'sport_type' => 'football',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(), 'registration_close' => Carbon::now()->addDays(10),
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => $fee, 'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    /**
     * The organizer's own destination, which is where a manual registration fee
     * is paid. `is_primary` is load-bearing: PaymentRails and the resource both
     * read the primary one and nothing else.
     */
    private function bankAccount(Event $event): void
    {
        $event->organization->bankAccounts()->create([
            'bank_name' => 'BCA',
            'bank_code' => '014',
            'account_number' => '1234567890',
            'account_holder' => 'Flo Event EO',
            'is_primary' => true,
        ]);
    }

    /**
     * `$channel` is required exactly when the fee is a paid gateway one — the
     * rule lives in RegistrationService::startPayment(), so the tests that
     * compare the two rails pass it on the gateway half and omit it elsewhere.
     */
    private function register(Event $event, User $user, ?string $channel = null): string
    {
        $org = $event->organization;

        return $this->actingAs($user, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'category_id' => $event->categories->first()->id,
                'name' => 'Garuda FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'payment_channel' => $channel,
                'players' => [
                    ['full_name' => 'Player One', 'jersey_number' => '10'],
                    ['full_name' => 'Player Two', 'jersey_number' => '7'],
                ],
            ])
            ->assertCreated()
            ->json('data.team.id');
    }

    public function test_participant_can_update_team_and_sync_roster(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $playerId = $this->actingAs($user, 'api')->getJson("/api/v1/my-teams/{$teamId}")
            ->json('data.players.0.id');

        // Rename team, update one existing player, add a new one, drop the other.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", [
                'name' => 'Garuda United',
                'players' => [
                    ['id' => $playerId, 'full_name' => 'Player One Edited', 'jersey_number' => '11'],
                    ['full_name' => 'Player Three', 'jersey_number' => '9'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Garuda United');

        $this->assertDatabaseHas('teams', ['id' => $teamId, 'name' => 'Garuda United']);
        $this->assertDatabaseHas('players', ['id' => $playerId, 'full_name' => 'Player One Edited']);
        $this->assertDatabaseHas('players', ['team_id' => $teamId, 'full_name' => 'Player Three']);
        $this->assertDatabaseMissing('players', ['team_id' => $teamId, 'full_name' => 'Player Two']);
        $this->assertDatabaseCount('players', 2);
    }

    public function test_manager_can_print_own_teams_album(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $response = $this->actingAs($user, 'api')
            ->get("/api/v1/my-teams/{$teamId}/album");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_manager_cannot_print_another_managers_team_album(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $owner);

        $this->actingAs($stranger, 'api')
            ->get("/api/v1/my-teams/{$teamId}/album")
            ->assertStatus(404);
    }

    public function test_participant_can_withdraw_team(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/my-teams/{$teamId}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $this->assertDatabaseHas('teams', ['id' => $teamId, 'status' => 'withdrawn']);
    }

    public function test_withdrawn_team_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $this->actingAs($user, 'api')->postJson("/api/v1/my-teams/{$teamId}/withdraw")->assertOk();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", ['name' => 'Nope'])
            ->assertStatus(422);
    }

    public function test_participant_cannot_access_others_team(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $owner);

        $this->actingAs($stranger, 'api')->getJson("/api/v1/my-teams/{$teamId}")->assertStatus(404);
    }

    /**
     * Compared, not asserted alone: the same request runs twice against the same
     * team, and only the event's clock differs. Asserting the 422 by itself
     * would pass just as well if the endpoint had been broken outright.
     */
    public function test_closed_registration_freezes_the_participants_own_team(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $payload = [
            'name' => 'Garuda United',
            'players' => [
                ['full_name' => 'Player One', 'jersey_number' => '10'],
                ['full_name' => 'Player Three', 'jersey_number' => '9'],
            ],
        ];

        // Door open: the edit lands.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", $payload)
            ->assertOk();
        $this->assertDatabaseHas('players', ['team_id' => $teamId, 'full_name' => 'Player Three']);

        // Same team, same payload, clock run out.
        $event->update(['registration_close' => Carbon::now()->subDay()]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", [
                'name' => 'Garuda Rejected',
                'players' => [['full_name' => 'Player Four', 'jersey_number' => '4']],
            ])
            ->assertStatus(422);

        // The roster from before the deadline is what survived — a 422 that still
        // wrote would pass an assertion on the status code alone.
        $this->assertDatabaseHas('teams', ['id' => $teamId, 'name' => 'Garuda United']);
        $this->assertDatabaseHas('players', ['team_id' => $teamId, 'full_name' => 'Player Three']);
        $this->assertDatabaseMissing('players', ['team_id' => $teamId, 'full_name' => 'Player Four']);
    }

    /**
     * The window closes the form, not the exit. Withdrawing and settling an
     * outstanding fee stay open on purpose — see MyTeamController::isEditable().
     */
    public function test_closed_registration_still_allows_withdrawing(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $event->update(['registration_close' => Carbon::now()->subDay()]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/my-teams/{$teamId}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');
    }

    /**
     * The status half of the same rule: the dates can still be wide open while
     * the organizer has shut the door by hand. Both halves live in
     * Event::isRegistrationOpen(), which is exactly why the controller asks it
     * rather than comparing dates itself.
     */
    public function test_registration_closed_status_freezes_the_team_too(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent();
        $teamId = $this->register($event, $user);

        $event->update(['status' => 'registration_closed']);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/my-teams/{$teamId}", ['name' => 'Nope'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('teams', ['id' => $teamId, 'name' => 'Nope']);
    }

    /**
     * The destination travels to the single-team page and NOT to the list.
     *
     * Asserting it is present here would pass just as well if the list started
     * publishing it too, which is the leak worth designing out: payTo() only
     * answers when `event.organization.bankAccounts` is eager-loaded, so "the
     * index does not load it" *is* the enforcement — and that is invisible from
     * the controller. Same reasoning already written for
     * MyRaceEntryController::index().
     *
     * The value is what is checked, not the key: `when()` branches on the
     * payment's state, so a present-but-null key proves nothing.
     */
    public function test_manual_registration_publishes_destination_to_the_detail_page_only(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent(150000);
        $event->update(['payment_method' => 'manual']);
        $this->bankAccount($event);

        $teamId = $this->register($event, $user);

        $this->actingAs($user, 'api')->getJson("/api/v1/my-teams/{$teamId}")
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'manual')
            ->assertJsonPath('data.bank_account.account_number', '1234567890')
            ->assertJsonPath('data.bank_account.account_holder', 'Flo Event EO');

        $this->actingAs($user, 'api')->getJson('/api/v1/my-teams')
            ->assertOk()
            ->assertJsonPath('data.0.id', $teamId)
            ->assertJsonPath('data.0.bank_account', null);
    }

    /**
     * Settling the bill withdraws the destination, and the pair is the test:
     * asserting only that a paid team carries no account would pass on a build
     * that never published one at all.
     */
    public function test_destination_disappears_once_the_registration_is_paid(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent(150000);
        $event->update(['payment_method' => 'manual']);
        $this->bankAccount($event);

        $teamId = $this->register($event, $user);

        $this->actingAs($user, 'api')->getJson("/api/v1/my-teams/{$teamId}")
            ->assertJsonPath('data.bank_account.account_number', '1234567890');

        Team::find($teamId)->update(['payment_status' => 'paid', 'paid_at' => Carbon::now()]);

        $this->actingAs($user, 'api')->getJson("/api/v1/my-teams/{$teamId}")
            ->assertOk()
            ->assertJsonPath('data.bank_account', null);
    }

    /**
     * Reopening payment on the manual rail answers with the destination AND
     * leaves the fee unpaid.
     *
     * The pair matters: a 200 alone would pass on the build this test was
     * written for, where the page read that response as a success and announced
     * "Pembayaran berhasil" over money that never moved. Compared against the
     * gateway rail on the same event, in the same plan, at the same price — so
     * the rail is the only thing that can explain the difference.
     */
    public function test_reopening_a_manual_fee_returns_the_destination_and_stays_unpaid(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent(150000);
        $event->update(['payment_method' => 'manual']);
        $this->bankAccount($event);

        $teamId = $this->register($event, $user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/my-teams/{$teamId}/pay")
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'manual')
            ->assertJsonPath('data.bank_account.account_number', '1234567890')
            ->assertJsonPath('data.redirect_url', null)
            ->assertJsonPath('data.team.payment_status', 'unpaid')
            // The row carries the destination too, which is what the page
            // re-renders the panel from after invalidating its query.
            ->assertJsonPath('data.team.bank_account.account_number', '1234567890');

        $this->assertDatabaseHas('teams', [
            'id' => $teamId,
            'payment_status' => 'unpaid',
            'payment_method' => 'manual',
        ]);

        // Same event, same price, gateway rail: no destination comes back.
        // Without this half, a build that stamped every registration manual
        // would pass everything above.
        //
        // Compared at registration rather than through a second /pay, because
        // with no Midtrans server key the gateway rail settles immediately
        // (`mock`) and the retry then rightly refuses an already-paid bill.
        $event->update(['payment_method' => 'gateway']);
        $other = User::factory()->create();
        $org = $event->organization;

        $this->actingAs($other, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'category_id' => $event->categories->first()->id,
                'name' => 'Rajawali FC',
                'contact_name' => 'Budi',
                'contact_phone' => '08123456780',
                'payment_channel' => 'va',
                'players' => [['full_name' => 'Player Three', 'jersey_number' => '3']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'gateway')
            ->assertJsonPath('data.bank_account', null);
    }

    /**
     * Uploading the receipt queues it, and the row keeps the money unpaid until
     * an org admin rules on it.
     *
     * `awaiting_verification` is derived (see HasManualPayment), so the before/
     * after pair is what proves it: asserting `true` alone would pass on a build
     * that reports it for every unpaid team.
     */
    public function test_manager_can_submit_proof_for_a_manual_registration_fee(): void
    {
        $user = User::factory()->create();
        $event = $this->openEvent(150000);
        $event->update(['payment_method' => 'manual']);
        $this->bankAccount($event);

        $teamId = $this->register($event, $user);

        $this->actingAs($user, 'api')->getJson("/api/v1/my-teams/{$teamId}")
            ->assertJsonPath('data.awaiting_verification', false);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/my-teams/{$teamId}/proof", [
                'payment_proof_url' => 'https://cdn.test/proof.webp',
            ])
            ->assertOk()
            ->assertJsonPath('data.awaiting_verification', true)
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertDatabaseHas('teams', [
            'id' => $teamId,
            'payment_proof_url' => 'https://cdn.test/proof.webp',
            'payment_status' => 'unpaid',
        ]);
    }
}
