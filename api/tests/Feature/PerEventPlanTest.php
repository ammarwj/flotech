<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Entitlements belong to an event, not to an organization.
 *
 * Every test here compares two things that must come out different — two events
 * of one organizer, two calls to one endpoint, a plan that grants a feature
 * against one that does not. Asserting only that "the refusal happened" passes
 * just as happily against a gate that refuses everybody, and asserting only that
 * "it worked" passes against one that refuses nobody. Neither is the failure
 * mode this change actually has, which is a gate that still reads the
 * organization.
 */
class PerEventPlanTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    /** @param array<string, string> $features */
    private function url(Organization $org, Event $event, string $path): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}/{$path}";
    }

    // ---- the keystone -------------------------------------------------------

    /**
     * One organization, two events, two answers.
     *
     * Asserting only the Professional event works would pass under an org-keyed
     * gate reading the organization's "best" plan — which is exactly what this
     * change replaced. The pair is the proof.
     */
    public function test_two_events_of_one_organization_get_different_entitlements(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);

        $small = $this->eventOn($org, $this->planWith([]));
        $big = $this->eventOn($org, $this->planWith(['event_gallery' => 'true', 'max_gallery_photos' => '-1']));

        $photo = ['photos' => [['photo_url' => 'https://cdn.test/a.jpg']]];

        $this->actingAs($user, 'api')
            ->postJson($this->url($org, $small, 'photos'), $photo)
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'event_gallery');

        $this->actingAs($user, 'api')
            ->postJson($this->url($org, $big, 'photos'), $photo)
            ->assertCreated();
    }

    /**
     * Identical price, different fee.
     *
     * A single order's fee would come out right under any constant percentage —
     * including one still read off the organization. Two events at one price is
     * the only shape that pins it to the event.
     */
    public function test_platform_fee_comes_from_the_event_plan_not_the_organization(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);

        $cheap = $this->eventOn($org, $this->planWith([
            'qr_tickets' => 'true', 'payment_gateway' => 'true', 'platform_fee_percent' => '3',
        ]));
        $dear = $this->eventOn($org, $this->planWith([
            'qr_tickets' => 'true', 'payment_gateway' => 'true', 'platform_fee_percent' => '1',
        ]));

        $fees = [];

        foreach ([$cheap, $dear] as $event) {
            $category = $event->ticketCategories()->create([
                'name' => 'Reguler', 'price' => 100000, 'is_active' => true,
            ]);

            $orderId = $this->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/tickets/purchase", [
                'ticket_category_id' => $category->id,
                'quantity' => 1,
                'buyer_name' => 'Budi',
                'buyer_email' => 'budi@test.com',
            ])->assertCreated()->json('data.order.id');

            $fees[] = (float) $event->ticketOrders()->findOrFail($orderId)->platform_fee;
        }

        $this->assertSame([3000.0, 1000.0], $fees, 'The fee must follow the event, not the organization.');
    }

    /**
     * The registration fee reads the same key off the same event — and manual
     * money still never reaches the wallet, on either plan.
     */
    public function test_registration_fee_uses_the_same_key_and_manual_never_touches_the_wallet(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);
        $org->bankAccounts()->create([
            'bank_name' => 'BCA', 'account_number' => '123', 'account_holder' => 'EO', 'is_primary' => true,
        ]);

        foreach ([['3', 3000.0], ['1', 1000.0]] as [$percent, $expected]) {
            $event = $this->eventOn($org, $this->planWith([
                'online_registration' => 'true', 'payment_gateway' => 'true', 'platform_fee_percent' => $percent,
            ]), ['registration_open' => now()->subDay(), 'registration_close' => now()->addDays(10)]);

            $category = $event->categories()->create([
                'name' => 'Umum', 'slug' => 'umum-'.uniqid(), 'tournament_format' => 'league',
                'registration_fee' => 100000, 'sort_order' => 0,
            ]);

            // Registering publicly needs an account: the team is tied to the
            // manager who filed it.
            $manager = User::factory()->create();

            // Gateway rail: the plan's percentage applies.
            $gateway = $this->actingAs($manager, 'api')->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                'category_id' => $category->id,
                'name' => 'Gateway FC',
                'contact_name' => 'Andi',
                'contact_phone' => '08123456789',
                'players' => [['full_name' => 'P1', 'jersey_number' => '1']],
            ])->assertCreated()->json('data.team.id');

            $this->assertSame($expected, (float) $event->teams()->findOrFail($gateway)->platform_fee);

            // Manual rail, same event, same price: no fee and no ledger entry,
            // because the money went straight to the organizer's own account.
            $this->withGatewayOff(function () use ($org, $event, $category, $manager) {
                $manual = $this->actingAs($manager, 'api')->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                    'category_id' => $category->id,
                    'name' => 'Manual FC',
                    'contact_name' => 'Budi',
                    'contact_phone' => '08123456780',
                    'players' => [['full_name' => 'P2', 'jersey_number' => '2']],
                ])->assertCreated()->json('data.team.id');

                $team = $event->teams()->findOrFail($manual);
                $this->assertSame(0.0, (float) $team->platform_fee);
                $this->assertDatabaseMissing('wallet_transactions', [
                    'source_type' => 'team', 'source_id' => $team->id,
                ]);
            });
        }
    }

    // ---- the credit ledger --------------------------------------------------

    /**
     * Holding two credits, the organizer picks which to spend.
     *
     * FIFO would have taken the Starter — so asserting only that the event was
     * created proves nothing. The untouched credit and the working cap do.
     */
    public function test_the_organizer_can_choose_which_credit_to_spend(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);

        $small = $this->creditFor($org, $this->planWith(['max_categories' => '1']));
        $big = $this->creditFor($org, $this->planWith(['max_categories' => '-1']));

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Kejurnas',
                'sport_type' => 'football',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-10',
                'plan_order_id' => $big->id,
                'categories' => [
                    ['name' => 'A', 'tournament_format' => 'league'],
                    ['name' => 'B', 'tournament_format' => 'league'],
                    ['name' => 'C', 'tournament_format' => 'league'],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(3, 'data.categories');

        $this->assertNotNull($big->fresh()->event_id, 'The chosen credit must be consumed.');
        $this->assertNull($small->fresh()->event_id, 'The credit the organizer did not pick must stay unspent.');
    }

    /**
     * Buying a bigger plan later does not upgrade an event that already exists.
     *
     * Reading `events.plan_id` passes this; reading "the organization's newest
     * paid order" does not — and that is the shortcut this model invites.
     */
    public function test_an_event_keeps_its_plan_when_a_bigger_one_is_bought_later(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);

        $event = $this->eventOn($org, $this->planWith(['max_categories' => '1']));

        // A far more generous credit, bought afterwards and left unspent.
        $this->creditFor($org, $this->planWith(['max_categories' => '-1']));

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}", [
                'categories' => [
                    ['name' => 'A', 'tournament_format' => 'league'],
                    ['name' => 'B', 'tournament_format' => 'league'],
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'max_categories');
    }

    /**
     * A refused create leaves nothing behind — not the event, not a burnt credit.
     *
     * The 403 alone would pass with a half-created event and a spent credit,
     * which is precisely what the missing transaction used to produce.
     */
    public function test_max_categories_rejects_the_whole_create(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);
        $credit = $this->creditFor($org, $this->planWith(['max_categories' => '1']));

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Dua Kategori',
                'sport_type' => 'football',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-10',
                'categories' => [
                    ['name' => 'A', 'tournament_format' => 'league'],
                    ['name' => 'B', 'tournament_format' => 'league'],
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'max_categories');

        $this->assertSame(0, $org->events()->count(), 'No event may survive a refused create.');
        $this->assertNull($credit->fresh()->event_id, 'A refused create must not burn the credit.');
    }

    // ---- caps ---------------------------------------------------------------

    /**
     * The organizer's own per-category cap may not exceed the plan's — but a
     * value at the cap is fine. Both halves in one test, because a rule that
     * refuses everything would pass the refusal on its own.
     */
    public function test_category_max_teams_cannot_exceed_the_plan_cap(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);
        $this->creditFor($org, $this->planWith(['max_teams_per_category' => '32']));

        $payload = fn (int $max) => [
            'name' => 'Cup '.$max,
            'sport_type' => 'football',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
            'categories' => [['name' => 'Umum', 'tournament_format' => 'league', 'max_teams' => $max]],
        ];

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $payload(100))
            ->assertStatus(422)
            ->assertJsonValidationErrors('categories.0.max_teams');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $payload(32))
            ->assertCreated();
    }

    /**
     * The cap is per category, not per event.
     *
     * Two categories each filled to the cap is the whole point: under the old
     * `max_teams_per_event` the third entrant overall would have been refused,
     * so only this pairing proves the semantics changed.
     */
    public function test_teams_are_capped_per_category_not_per_event(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org, $this->planWith(['max_teams_per_category' => '2']));

        $a = $event->categories()->create(['name' => 'A', 'slug' => 'a', 'tournament_format' => 'league', 'sort_order' => 0]);
        $b = $event->categories()->create(['name' => 'B', 'slug' => 'b', 'tournament_format' => 'league', 'sort_order' => 1]);

        $add = fn ($category, string $name) => $this->actingAs($user, 'api')
            ->postJson($this->url($org, $event, 'registrations'), [
                'category_id' => $category->id,
                'name' => $name,
                'players' => [['full_name' => 'P', 'jersey_number' => '1']],
            ]);

        // Two in each — four in the event, and all of them allowed.
        $add($a, 'A1')->assertCreated();
        $add($a, 'A2')->assertCreated();
        $add($b, 'B1')->assertCreated();
        $add($b, 'B2')->assertCreated();

        // The third in A is refused; B is untouched by A being full.
        $add($a, 'A3')->assertStatus(403)->assertJsonPath('errors.feature', 'max_teams_per_category');
        $this->assertSame(4, $event->teams()->count());
    }

    /**
     * The gallery cap counts the event's total, not one request.
     *
     * Under the per-request `max:50` guard both calls below pass, so comparing
     * them is the only thing that proves the total is what is counted.
     */
    public function test_gallery_cap_counts_the_event_total_not_one_request(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);
        $event = $this->eventOn($org, $this->planWith([
            'event_gallery' => 'true', 'max_gallery_photos' => '15',
        ]));

        $photos = fn (int $n) => ['photos' => array_map(
            fn ($i) => ['photo_url' => "https://cdn.test/{$i}.jpg"],
            range(1, $n),
        )];

        $this->actingAs($user, 'api')->postJson($this->url($org, $event, 'photos'), $photos(10))->assertCreated();
        $this->actingAs($user, 'api')->postJson($this->url($org, $event, 'photos'), $photos(10))
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'max_gallery_photos');

        $this->assertSame(10, $event->photos()->count(), 'The refused batch must not be partially stored.');
    }

    /**
     * Without the boolean there is no gallery at all.
     *
     * This is the null-passes-freely trap: `withinLimit()` reads an absent
     * numeric cap as unlimited, so a numeric-only key would hand every plan an
     * uncapped gallery. The boolean is what denies.
     */
    public function test_gallery_is_refused_outright_without_the_boolean(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);
        // Note: no `event_gallery`, and no `max_gallery_photos` either.
        $event = $this->eventOn($org, $this->planWith(['export_data' => 'true']));

        $this->actingAs($user, 'api')
            ->postJson($this->url($org, $event, 'photos'), ['photos' => [['photo_url' => 'https://cdn.test/a.jpg']]])
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'event_gallery');
    }

    /** Byte-identical requests, two events, two answers. */
    public function test_sponsor_logo_is_refused_on_one_plan_and_accepted_on_another(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);

        $without = $this->eventOn($org, $this->planWith([]));
        $with = $this->eventOn($org, $this->planWith(['sponsor_logos' => 'true']));

        $body = ['name' => 'Sponsor A', 'logo_url' => 'https://cdn.test/l.png'];

        $this->actingAs($user, 'api')->postJson($this->url($org, $without, 'sponsors'), $body)
            ->assertStatus(403)->assertJsonPath('errors.feature', 'sponsor_logos');
        $this->actingAs($user, 'api')->postJson($this->url($org, $with, 'sponsors'), $body)
            ->assertCreated();
    }

    /** Export is an entitlement of the event, and it produces a real file. */
    public function test_export_requires_the_plan_and_returns_a_real_file(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);

        $without = $this->eventOn($org, $this->planWith([]));
        $with = $this->eventOn($org, $this->planWith(['export_data' => 'true']));

        $this->actingAs($user, 'api')
            ->get($this->url($org, $without, 'exports/registrations'))
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'export_data');

        $response = $this->actingAs($user, 'api')
            ->get($this->url($org, $with, 'exports/registrations'))
            ->assertOk();

        $this->assertNotSame('', $response->streamedContent(), 'The export must not be an empty file.');
    }

    // ---- the public doors ---------------------------------------------------

    /**
     * `online_registration` and `qr_tickets` are true on every catalogue plan,
     * so without a hand-written plan that omits them their enforcement is never
     * exercised at all.
     */
    public function test_online_registration_and_qr_tickets_can_be_switched_off_per_event(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);

        $dates = ['registration_open' => now()->subDay(), 'registration_close' => now()->addDays(10)];
        $off = $this->eventOn($org, $this->planWith([]), $dates);
        $on = $this->eventOn($org, $this->planWith([
            'online_registration' => 'true', 'qr_tickets' => 'true', 'payment_gateway' => 'true',
        ]), $dates);

        foreach ([$off, $on] as $event) {
            $event->categories()->create([
                'name' => 'Umum', 'slug' => 'umum-'.uniqid(), 'tournament_format' => 'league',
                'registration_fee' => 0, 'sort_order' => 0,
            ]);
            $event->ticketCategories()->create(['name' => 'Gratis', 'price' => 0, 'is_active' => true]);
        }

        $manager = User::factory()->create();

        $register = fn (Event $e) => $this->actingAs($manager, 'api')
            ->postJson("/api/v1/public/events/{$org->slug}/{$e->slug}/register", [
            'category_id' => $e->categories()->first()->id,
            'name' => 'FC',
            'contact_name' => 'Andi',
            'contact_phone' => '08123456789',
            'players' => [['full_name' => 'P', 'jersey_number' => '1']],
        ]);

        $buy = fn (Event $e) => $this->postJson("/api/v1/public/events/{$org->slug}/{$e->slug}/tickets/purchase", [
            'ticket_category_id' => $e->ticketCategories()->first()->id,
            'quantity' => 1,
            'buyer_name' => 'Budi',
            'buyer_email' => 'budi@test.com',
        ]);

        $register($off)->assertStatus(422)->assertJsonPath('errors.feature', 'online_registration');
        $register($on)->assertCreated();

        $buy($off)->assertStatus(422)->assertJsonPath('errors.feature', 'qr_tickets');
        $buy($on)->assertCreated();
    }

    /**
     * The public profile fills in only once an event carries the entitlement.
     *
     * Asserting the rich shape on its own would pass with no gate at all, so the
     * before/after pair is the test.
     */
    public function test_the_public_profile_is_rich_only_once_an_event_carries_it(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);
        $org->update([
            'description' => 'EO terbaik',
            'contact_phone' => '08123456789',
            'social_links' => ['instagram' => 'https://instagram.com/eo'],
        ]);

        $this->eventOn($org, $this->planWith([]));

        $this->getJson("/api/v1/public/organizations/{$org->slug}")
            ->assertOk()
            ->assertJsonPath('data.has_profile', false)
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.social_links', []);

        $this->eventOn($org, $this->planWith(['organizer_profile' => 'true']));

        $this->getJson("/api/v1/public/organizations/{$org->slug}")
            ->assertOk()
            ->assertJsonPath('data.has_profile', true)
            ->assertJsonPath('data.description', 'EO terbaik')
            ->assertJsonPath('data.social_links.instagram', 'https://instagram.com/eo');
    }

    // ---- what the retired keys no longer do ---------------------------------

    /**
     * Keys the catalogue no longer carries must grant and deny nothing.
     *
     * Asserting the seeder stopped writing them would pass even if a controller
     * still read them, so this hands them to a plan on purpose and checks that
     * the limits they used to impose simply do not apply.
     */
    public function test_retired_keys_grant_nothing(): void
    {
        $user = $this->owner();
        $org = $this->orgFor($user);

        $plan = $this->planWith([
            'max_active_events' => '1',   // used to cap events per organization
            'max_teams_per_event' => '1', // used to cap entrants per event
            'max_tickets_per_event' => '1',
            'qr_tickets' => 'true',
        ]);

        // Two events on a plan that used to allow one.
        $this->creditFor($org, $plan);
        $this->creditFor($org, $plan);

        $payload = fn (string $name) => [
            'name' => $name,
            'sport_type' => 'football',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
            'categories' => [['name' => 'Umum', 'tournament_format' => 'league']],
        ];

        $this->actingAs($user, 'api')->postJson("/api/v1/organizations/{$org->id}/events", $payload('Satu'))->assertCreated();
        $this->actingAs($user, 'api')->postJson("/api/v1/organizations/{$org->id}/events", $payload('Dua'))->assertCreated();

        // Five entrants on a plan that used to allow one per event.
        $event = $org->events()->firstOrFail();
        $category = $event->categories()->firstOrFail();

        foreach (range(1, 5) as $i) {
            $this->actingAs($user, 'api')
                ->postJson($this->url($org, $event, 'registrations'), [
                    'category_id' => $category->id,
                    'name' => "Tim {$i}",
                    'players' => [['full_name' => 'P', 'jersey_number' => (string) $i]],
                ])
                ->assertCreated();
        }

        // And an unlimited ticket quota on a plan that used to cap it at one.
        $this->actingAs($user, 'api')
            ->postJson($this->url($org, $event, 'ticket-categories'), ['name' => 'Reguler', 'price' => 0, 'quota' => 500])
            ->assertCreated();
    }

    // ---- who may spend the organization's money -----------------------------

    /**
     * Creating an event spends a credit, so it moved behind org.admin. An
     * operator is a full tenant member — `tenant` alone would let the gate
     * scanner burn a plan the owner paid for.
     */
    public function test_an_operator_cannot_create_an_event(): void
    {
        $owner = $this->owner();
        $operator = User::factory()->create();
        $org = $this->orgFor($owner);
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);
        $credit = $this->creditFor($org);

        $this->actingAs($operator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'Diam-diam',
                'sport_type' => 'football',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-10',
                'categories' => [['name' => 'Umum', 'tournament_format' => 'league']],
            ])
            ->assertStatus(403);

        $this->assertNull($credit->fresh()->event_id);
        $this->assertSame(0, $org->events()->count());
    }

    // ---- the escape hatch ---------------------------------------------------

    /**
     * A super admin can move an event onto another paid plan, and the gates move
     * with it. The before/after on one event is the point — asserting only that
     * `plan_id` changed would pass even if PlanGate cached the old answer.
     */
    public function test_super_admin_can_move_an_event_onto_another_plan(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $org = $this->orgFor($owner);

        $event = $this->eventOn($org, $this->planWith([]));
        $replacement = $this->creditFor($org, $this->planWith([
            'event_gallery' => 'true', 'max_gallery_photos' => '-1',
        ]));

        $photo = ['photos' => [['photo_url' => 'https://cdn.test/a.jpg']]];

        $this->actingAs($owner, 'api')->postJson($this->url($org, $event, 'photos'), $photo)->assertStatus(403);

        // An org admin may not do this — the party who benefits is the one asking.
        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/admin/events/{$event->id}/reassign-plan", ['plan_order_id' => $replacement->id])
            ->assertStatus(403);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/events/{$event->id}/reassign-plan", ['plan_order_id' => $replacement->id])
            ->assertOk();

        $this->actingAs($owner, 'api')->postJson($this->url($org, $event, 'photos'), $photo)->assertCreated();

        // One order, one event — still a database fact after the move.
        $this->assertSame($event->id, $replacement->fresh()->event_id);
        $this->assertSame(1, $org->planOrders()->whereNotNull('event_id')->count());
    }

    /** A credit already spent elsewhere cannot be moved onto a second event. */
    public function test_reassign_refuses_a_credit_that_is_already_spent(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $org = $this->orgFor($this->owner());

        $first = $this->eventOn($org, $this->planWith([]));
        $second = $this->eventOn($org, $this->planWith([]));
        $spent = $org->planOrders()->whereNotNull('event_id')->firstOrFail();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/events/{$second->id}/reassign-plan", ['plan_order_id' => $spent->id])
            ->assertStatus(422);

        $this->assertSame($first->id, $spent->fresh()->event_id);
    }

    /**
     * A re-delivered webhook must not mint a second receipt — and must still
     * write nothing to the organization.
     *
     * The second half is the part worth pinning: a paid order is a *credit*, and
     * the event it will pay for does not exist yet. An activate() that "helpfully"
     * stamped the organization would recreate the org-level plan this whole
     * change removed.
     */
    public function test_activate_is_idempotent_and_writes_nothing_to_the_organization(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $owner = $this->owner();
        $org = $this->orgFor($owner);
        $plan = $this->planWith([]);
        $plan->update(['price' => 150000]);

        $service = app(\App\Services\EventPlanOrderService::class);
        $order = $service->checkout($org, $plan)['order'];

        $before = $org->fresh()->getAttributes();

        $service->activate($order->fresh(), 'bank_transfer');
        $receipt = $order->fresh()->receipt_number;
        $paidAt = $order->fresh()->paid_at;

        // Midtrans re-delivers.
        $service->activate($order->fresh(), 'bank_transfer');

        $order->refresh();
        $this->assertSame($receipt, $order->receipt_number, 'A second webhook must not reissue the receipt.');
        $this->assertEquals($paidAt, $order->paid_at);
        $this->assertNull($order->event_id, 'Settling pays a bill; it does not spend the credit.');

        $this->assertSame($before, $org->fresh()->getAttributes(), 'Nothing may be written to the organization.');

        \Illuminate\Support\Facades\Notification::assertSentToTimes(
            $owner,
            \App\Notifications\PlanOrderPaid::class,
            1,
        );
    }

    /** Run something with the payment gateway switched off. */
    private function withGatewayOff(callable $fn): void
    {
        \App\Models\PlatformSetting::updateOrCreate(
            ['key' => 'payment_gateway_enabled'],
            ['value' => '0', 'type' => 'bool'],
        );
        \App\Services\PlatformSettings::flush();

        try {
            $fn();
        } finally {
            \App\Models\PlatformSetting::updateOrCreate(
                ['key' => 'payment_gateway_enabled'],
                ['value' => '1', 'type' => 'bool'],
            );
            \App\Services\PlatformSettings::flush();
        }
    }
}
