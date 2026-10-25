<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

class EventTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /**
     * An organization holding `$credits` unspent plan orders, since creating an
     * event now spends one.
     *
     * @param  array<string, string>  $features
     */
    private function orgWithPlan(User $owner, array $features = [], int $credits = 1): Organization
    {
        $org = $this->orgFor($owner);
        $plan = $features === [] ? $this->fullPlan() : $this->planWith($features);

        for ($i = 0; $i < $credits; $i++) {
            $this->creditFor($org, $plan);
        }

        return $org;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jakarta Cup 2026',
            'sport_type' => 'football',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
            'categories' => [
                ['name' => 'Umum', 'tournament_format' => 'league', 'registration_fee' => 1500000],
            ],
        ], $overrides);
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
     * Manual is refused until there is somewhere for the money to go — then the
     * *same payload* is accepted. Asserting the 422 alone would pass just as
     * well if the field were never stored at all.
     */
    public function test_saving_an_event_on_manual_without_a_bank_account_is_refused_then_accepted(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $payload = $this->payload(['payment_method' => 'manual']);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->bankAccount($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $payload)
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'manual')
            ->assertJsonPath('data.effective_payment_method', 'manual');

        $this->assertDatabaseHas('events', ['organization_id' => $org->id, 'payment_method' => 'manual']);
    }

    /**
     * The refusal happens inside the transaction that spends the credit, so it
     * has to roll back — otherwise a mistyped rail costs the organizer a plan.
     */
    public function test_a_refused_payment_method_does_not_burn_the_plan_credit(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload(['payment_method' => 'manual']))
            ->assertStatus(422);

        $this->assertSame(1, $org->planOrders()->unconsumed()->count());
        $this->assertDatabaseCount('events', 0);
    }

    /**
     * Picking the gateway the plan doesn't carry is a 422 on the field, not the
     * 403 the checkout throws — this is a select on a form, and a feature toast
     * would leave it unmarked. The manual retry proves the event was otherwise
     * fine.
     */
    public function test_choosing_the_gateway_on_a_plan_without_it_is_refused_at_save_time(): void
    {
        $user = User::factory()->create();
        $org = $this->orgFor($user);
        $plan = $this->fullPlan();
        $plan->features()->where('feature_key', 'payment_gateway')->update(['value' => 'false']);
        $this->creditFor($org, $plan);
        $this->bankAccount($org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload(['payment_method' => 'gateway']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method')
            ->assertJsonMissingPath('errors.feature');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload(['payment_method' => 'manual']))
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'manual');
    }

    /**
     * The `array_key_exists` guard. An account can be deleted long after the
     * event was saved on manual, and the organizer must still be able to edit
     * everything else — a 422 on a field that isn't on their screen is a dead
     * end with no way out of it.
     */
    public function test_editing_an_unrelated_field_does_not_revalidate_the_payment_method(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $this->bankAccount($org);

        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload(['payment_method' => 'manual']))
            ->assertCreated()
            ->json('data.id');

        $org->bankAccounts()->delete();

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$eventId}", ['name' => 'Renamed Cup'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Cup')
            ->assertJsonPath('data.payment_method', 'manual');

        // Sending it explicitly is still refused — the guard narrows when the
        // rule runs, it does not remove it.
        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$eventId}", ['payment_method' => 'manual'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_member_can_create_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'jakarta-cup-2026')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('events', ['organization_id' => $org->id, 'name' => 'Jakarta Cup 2026']);
    }

    /**
     * One credit buys one event.
     *
     * Compares the two calls rather than asserting the refusal alone: a 403 on
     * its own would pass just as happily if the second event had been created
     * anyway, which is the failure this guards.
     */
    public function test_one_credit_creates_exactly_one_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->assertCreated();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload(['name' => 'Second']))
            ->assertStatus(403)
            ->assertJsonPath('errors.feature', 'plan_order_required');

        $this->assertSame(1, $org->events()->count());
        $this->assertSame(0, $org->planOrders()->unconsumed()->count());
    }

    public function test_non_member_cannot_create_event(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->assertStatus(403);
    }

    public function test_member_can_update_and_publish_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$eventId}", ['name' => 'Renamed Cup'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Cup');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    /** A published event, ready to be walked through the rest of its life. */
    private function openEvent(User $user, Organization $org): string
    {
        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventId}/publish")
            ->assertOk();

        return $eventId;
    }

    private function statusUrl(Organization $org, string $eventId): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$eventId}/status";
    }

    public function test_event_walks_through_its_statuses(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        foreach (['registration_closed', 'ongoing', 'finished'] as $status) {
            $this->actingAs($user, 'api')
                ->patchJson($this->statusUrl($org, $eventId), ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'finished']);
    }

    public function test_next_statuses_are_published_with_the_event(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        // The dashboard renders its buttons from this, so it must match the table.
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$eventId}")
            ->assertOk()
            ->assertJsonPath('data.next_statuses', ['registration_closed', 'ongoing', 'finished', 'cancelled']);
    }

    public function test_a_finished_event_is_terminal(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'finished'])
            ->assertOk()
            ->assertJsonPath('data.next_statuses', []);

        // Reopening would claim registrations for an event whose funds are out.
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'open'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'finished']);
    }

    public function test_a_draft_cannot_skip_straight_to_finished(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload())
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'finished'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'draft']);
    }

    public function test_registration_can_be_reopened(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'registration_closed'])
            ->assertOk();

        // The one move that goes backwards: closing registration is a mistake
        // an organizer must be able to undo.
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    /** An event cancelled from `$from`, ready to be reactivated. */
    private function cancelledFrom(User $user, Organization $org, string $from, string $name): string
    {
        $eventId = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload(['name' => $name]))
            ->json('data.id');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventId}/publish")
            ->assertOk();

        if ($from !== 'open') {
            $this->actingAs($user, 'api')
                ->patchJson($this->statusUrl($org, $eventId), ['status' => $from])
                ->assertOk();
        }

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'cancelled'])
            ->assertOk();

        return $eventId;
    }

    /**
     * Cancelling is the one move with a way back, and it goes back to exactly
     * where the event stood.
     *
     * Two events in the same org cancelled from different points, compared:
     * a restore that always landed on one fixed status would satisfy either of
     * them on its own.
     */
    public function test_a_cancelled_event_returns_to_where_it_stood(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user, credits: 2);

        $closed = $this->cancelledFrom($user, $org, 'registration_closed', 'Closed Cup');
        $running = $this->cancelledFrom($user, $org, 'ongoing', 'Running Cup');

        // The dashboard renders its one button from this.
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$closed}")
            ->assertJsonPath('data.next_statuses', ['registration_closed']);
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$running}")
            ->assertJsonPath('data.next_statuses', ['ongoing']);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $closed), ['status' => 'registration_closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'registration_closed');
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $running), ['status' => 'ongoing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ongoing');
    }

    public function test_reactivating_clears_the_snapshot(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->cancelledFrom($user, $org, 'registration_closed', 'Jakarta Cup 2026');

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'registration_closed'])
            ->assertOk();

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status_before_cancel' => null]);

        // Cancelled a second time from somewhere else, it comes back to the
        // status it left this time — not the one it left before.
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'ongoing'])
            ->assertOk();
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.next_statuses', ['ongoing']);
    }

    public function test_a_cancelled_event_can_only_be_reactivated(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->cancelledFrom($user, $org, 'open', 'Jakarta Cup 2026');

        // Coming back is the only move; finishing a cancelled event would pay
        // out money the platform stopped holding for it.
        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'finished'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'cancelled']);
    }

    /**
     * Reactivation never hands the plan credit back.
     *
     * A draft with nothing attached can be deleted, and deleting it returns the
     * credit — so a published event reaching `draft` through cancel-then-restore
     * would turn one payment into unlimited events. Counting the credits is the
     * test: asserting `draft` is absent from next_statuses would stay green even
     * if the order had been released along the way.
     */
    public function test_a_published_event_can_never_be_reactivated_into_a_draft(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->cancelledFrom($user, $org, 'open', 'Jakarta Cup 2026');

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'draft'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'open'])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$eventId}")
            ->assertStatus(422);

        $this->assertSame(0, $org->planOrders()->unconsumed()->count());
        $this->assertDatabaseHas('event_plan_orders', ['organization_id' => $org->id, 'event_id' => $eventId]);
    }

    /** Cancelled before the snapshot column existed: it still has a way back. */
    public function test_a_legacy_cancelled_event_falls_back_to_registration_closed(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->cancelledFrom($user, $org, 'ongoing', 'Jakarta Cup 2026');

        Event::where('id', $eventId)->update(['status_before_cancel' => null]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$eventId}")
            ->assertJsonPath('data.next_statuses', ['registration_closed']);

        $this->actingAs($user, 'api')
            ->patchJson($this->statusUrl($org, $eventId), ['status' => 'registration_closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'registration_closed');
    }

    public function test_saving_the_form_cannot_change_the_status(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        // The transition table would be pointless if the form save walked past
        // it — and 'finished' pays the organizer out.
        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/events/{$eventId}", [
                'name' => 'Renamed Cup',
                'status' => 'finished',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Cup')
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'open']);
    }

    public function test_publishing_an_already_published_event_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $eventId = $this->openEvent($user, $org);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$eventId}/publish")
            ->assertStatus(422);
    }

    public function test_status_cannot_be_changed_from_another_org(): void
    {
        $owner = User::factory()->create();
        $org = $this->orgWithPlan($owner);
        $eventId = $this->openEvent($owner, $org);

        $intruder = User::factory()->create();
        $otherOrg = $this->orgWithPlan($intruder, ['max_active_events' => '5']);

        $this->actingAs($intruder, 'api')
            ->patchJson($this->statusUrl($otherOrg, $eventId), ['status' => 'cancelled'])
            ->assertNotFound();

        $this->assertDatabaseHas('events', ['id' => $eventId, 'status' => 'open']);
    }

    // ---- description is rich text ------------------------------------------

    /**
     * > The mutator is the only sanitiser, so the STORED column is what
     * > matters — asserting the response alone would pass even if the resource
     * > were doing the cleaning and the row still held the script, which is
     * > the one place it would actually be served from.
     */
    public function test_scripts_are_stripped_from_the_description_before_it_is_stored(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $id = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload([
                'description' => '<p>Aman</p><script>alert(1)</script><p onclick="steal()">Klik</p>',
            ]))
            ->assertCreated()
            ->json('data.id');

        $stored = (string) Event::find($id)->description;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('<p>Aman</p>', $stored);
    }

    /**
     * The guard has to sit on the attribute, not in EventController: the column
     * has three writers already (the controller, DemoEventSeeder,
     * events:backfill-plan) and a fourth would not fail anywhere — the row
     * saves and the page renders, the only difference being whose script runs
     * in every visitor's browser.
     *
     * Compared against the same payload through the endpoint, because "model
     * cleans it" on its own would still pass if the request path had grown a
     * second, looser way in.
     */
    public function test_the_description_is_sanitised_no_matter_which_writer_saves_it(): void
    {
        $user = User::factory()->create();
        $dirty = '<p>Hai</p><script>alert(1)</script>';

        $viaApi = (string) Event::find(
            $this->actingAs($user, 'api')
                ->postJson(
                    "/api/v1/organizations/{$this->orgWithPlan($user)->id}/events",
                    $this->payload(['description' => $dirty])
                )
                ->assertCreated()
                ->json('data.id')
        )->description;

        // The second writer: a plain model save, standing in for the seeder and
        // the backfill command.
        $direct = Event::find($this->openEvent($user, $this->orgWithPlan($user)));
        $direct->update(['description' => $dirty]);

        $this->assertStringNotContainsString('<script', $viaApi);
        $this->assertSame($viaApi, (string) $direct->fresh()->description);
    }

    /**
     * An emptied editor emits `<p></p>`, which is 11 characters of "nothing".
     *
     * Stored as-is it is truthy everywhere, so the public page prints an empty
     * paragraph instead of "Belum ada deskripsi untuk event ini." — two
     * spellings of blank, and the branch that reads one of them is wrong.
     * Compared against a description that is only an image: that is also
     * text-free and must NOT be dropped, which is what tells this apart from a
     * plain `strip_tags` check.
     */
    public function test_an_emptied_editor_stores_null_but_an_image_only_description_survives(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);
        $id = $this->openEvent($user, $org);
        $url = "/api/v1/organizations/{$org->id}/events/{$id}";

        $this->actingAs($user, 'api')
            ->patchJson($url, ['description' => '<p></p>'])
            ->assertOk()
            ->assertJsonPath('data.description', null);

        $this->actingAs($user, 'api')
            ->patchJson($url, ['description' => '<img src="https://cdn.test/poster.webp" alt="">'])
            ->assertOk();

        $this->assertStringContainsString('<img', (string) Event::find($id)->description);
    }

    /**
     * A bare target="_blank" hands the opened tab a live `window.opener` back
     * into flo-event. The editor sets rel itself, but an organizer posting from
     * any other client would not — so the guarantee has to hold at the write
     * path rather than in the toolbar.
     */
    public function test_outbound_links_in_a_description_are_given_target_and_rel(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $id = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload([
                'description' => '<p><a href="https://contoh.test/aturan.pdf">Aturan</a></p>',
            ]))
            ->json('data.id');

        $stored = (string) Event::find($id)->description;

        $this->assertStringContainsString('target="_blank"', $stored);
        $this->assertStringContainsString('noopener', $stored);
    }

    /** javascript: survives neither the editor's check nor the server's. */
    public function test_javascript_urls_do_not_survive_the_description_sanitiser(): void
    {
        $user = User::factory()->create();
        $org = $this->orgWithPlan($user);

        $id = $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", $this->payload([
                'description' => '<p><a href="javascript:alert(1)">klik</a></p>',
            ]))
            ->json('data.id');

        $this->assertStringNotContainsString('javascript:', (string) Event::find($id)->description);
    }
}
