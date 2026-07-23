<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function openEvent(string $maxTeamsFeature = '10', ?int $maxTeams = null): Event
    {
        $owner = User::factory()->create();
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'price_monthly' => 0, 'price_yearly' => 0]);
        $plan->features()->create(['feature_key' => 'max_teams_per_event', 'value' => $maxTeamsFeature]);
        // PaymentRails refuses an online payment without this; every seeded plan grants it.
        $plan->features()->create(['feature_key' => 'payment_gateway', 'value' => 'true']);
        $org = Organization::create(['name' => 'EO', 'slug' => 'eo-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id]);

        $event = $org->events()->create([
            'name' => 'Cup', 'slug' => 'cup', 'sport_type' => 'football',
            'status' => 'open', 'start_date' => '2026-08-01', 'end_date' => '2026-08-10',
            'registration_open' => Carbon::now()->subDay(), 'registration_close' => Carbon::now()->addDays(10),
        ]);

        $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league',
            'registration_fee' => 0, 'max_teams' => $maxTeams, 'sort_order' => 0,
        ]);

        return $event->load('categories');
    }

    private function teamPayload(Event $event, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $event->categories->first()->id,
            'name' => 'Garuda FC',
            'contact_name' => 'Andi',
            'contact_phone' => '08123456789',
            'players' => [
                ['full_name' => 'Player One', 'jersey_number' => '10'],
                ['full_name' => 'Player Two', 'jersey_number' => '7'],
            ],
        ], $overrides);
    }

    public function test_public_can_view_published_event(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;

        $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Cup')
            ->assertJsonPath('data.registration_is_open', true);
    }

    public function test_draft_event_is_not_public(): void
    {
        $event = $this->openEvent();
        $event->update(['status' => 'draft']);
        $org = $event->organization;

        $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}")->assertStatus(404);
    }

    public function test_guest_cannot_register_team(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;

        // A team belongs to the manager who filed it — that link is what puts it
        // in their "Tim Saya". Registering with no account would orphan it.
        $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload($event))
            ->assertStatus(401);

        $this->assertDatabaseCount('teams', 0);
    }

    public function test_participant_can_register_team(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;
        $manager = User::factory()->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload($event))
            ->assertCreated()
            ->assertJsonPath('data.team.status', 'pending')
            ->assertJsonPath('data.team.name', 'Garuda FC')
            ->assertJsonPath('data.team.payment_status', 'paid'); // free event settles immediately

        $this->assertDatabaseHas('teams', [
            'event_id' => $event->id,
            'name' => 'Garuda FC',
            'status' => 'pending',
            'manager_user_id' => $manager->id,
        ]);
        $this->assertDatabaseCount('players', 2);
    }

    public function test_paid_event_registration_awaits_payment(): void
    {
        $event = $this->openEvent();
        $event->categories->first()->update(['registration_fee' => 150000]);
        $org = $event->organization;

        // No Midtrans credentials in tests → gateway is mocked and the fee
        // settles immediately (dev convenience), so the team is marked paid.
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload($event))
            ->assertCreated()
            ->assertJsonPath('data.team.payment_amount', 150000)
            ->assertJsonPath('data.team.payment_status', 'paid')
            ->assertJsonPath('data.mock', true);
    }

    public function test_registration_blocked_when_event_quota_full(): void
    {
        $event = $this->openEvent('10', maxTeams: 1);
        $org = $event->organization;

        $manager = User::factory()->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload($event))
            ->assertCreated();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload($event, ['name' => 'Other']))
            ->assertStatus(422);
    }

    public function test_organizer_can_approve_registration(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;

        $teamId = $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $this->teamPayload($event))
            ->json('data.team.id');

        $this->actingAs($org->owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$teamId}", [
                'status' => 'approved',
                'group_name' => 'A',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('teams', ['id' => $teamId, 'status' => 'approved', 'group_name' => 'A']);
    }

    public function test_team_can_be_registered_without_roster(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;

        // A manager without the squad list yet still gets a slot; the roster is
        // completed later from the participant dashboard.
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'category_id' => $event->categories->first()->id,
                'name' => 'Tanpa Roster',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('players', 0);
    }

    public function test_organizer_can_enter_team_manually(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;

        $this->actingAs($org->owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations", $this->teamPayload($event, [
                'name' => 'Tim Offline',
            ]))
            ->assertCreated()
            // The organizer entering it is the verification — no approval queue.
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.payment_status', 'paid');

        // The fee was settled outside the platform, so the platform holds none of
        // it: a wallet credit here would let the organizer withdraw money we
        // never received.
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_contact_is_optional_only_for_manual_entry(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;
        $payload = $this->teamPayload($event, ['contact_name' => null, 'contact_phone' => null]);

        // Compared side by side on purpose: asserting only that the organizer
        // route accepts a blank contact would still pass if the rule had been
        // dropped everywhere, silently letting public registrations through
        // with nobody to call.
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_name', 'contact_phone']);

        $this->actingAs($org->owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations", $payload)
            ->assertCreated()
            ->assertJsonPath('data.contact_name', null)
            ->assertJsonPath('data.contact_phone', null);
    }

    public function test_manual_team_respects_event_quota(): void
    {
        $event = $this->openEvent('10', maxTeams: 1);
        $org = $event->organization;

        $this->actingAs($org->owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations", $this->teamPayload($event))
            ->assertCreated();

        $this->actingAs($org->owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations", $this->teamPayload($event, ['name' => 'Other']))
            ->assertStatus(422);
    }

    public function test_registration_index_search_filters_by_name(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;
        $category = $event->categories->first();

        foreach (['Garuda FC', 'Elang Muda', 'Garuda United'] as $name) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => $name,
                'status' => 'approved',
                'registered_at' => Carbon::now(),
            ]);
        }

        // Case-insensitive substring — "garuda" must match both Garuda teams and
        // nothing else, so the picker never has to load the full list.
        $names = $this->actingAs($org->owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations?search=garuda")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data.*.name');

        $this->assertEqualsCanonicalizing(['Garuda FC', 'Garuda United'], $names);
    }

    public function test_registration_index_without_filters_returns_all(): void
    {
        $event = $this->openEvent();
        $org = $event->organization;
        $category = $event->categories->first();

        foreach (['A', 'B', 'C'] as $name) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => $name,
                'status' => 'approved',
                'registered_at' => Carbon::now(),
            ]);
        }

        // The standings/draw pages rely on the unfiltered list staying complete.
        $this->actingAs($org->owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }
}
