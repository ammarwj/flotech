<?php

namespace Tests\Feature;

use App\Mail\EventOperatorInvitedMail;
use App\Models\EventOperator;
use App\Models\EventOperatorInvite;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Event operators — someone an owner/admin hands a slice of ONE event's
 * organizer surface to, without an `organization_members` seat. Covers the
 * whole lifecycle (invite → accept → gated access → management) plus the
 * three QA findings fixed alongside this suite:
 *
 *  - UpdateEventOperatorRequest::withValidator() resolving a bare route
 *    string as a model (the 500 on a partial PATCH).
 *  - the four-category / money / org.admin invariants an operator must
 *    never cross, `matches/{match}/confirm` named explicitly because it is
 *    the single most sensitive one.
 *  - cross-org management returning 404, matching the pattern already
 *    verified for other org-scoped resources.
 */
class EventOperatorTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner): Organization
    {
        return $this->orgFor($owner);
    }

    private function event(Organization $org): Event
    {
        return $this->eventOn($org);
    }

    private function allPermissions(): array
    {
        return [
            'can_ticketing' => true,
            'can_scheduling' => true,
            'can_registration' => true,
            'can_documents' => true,
        ];
    }

    // ---- Invite ----

    public function test_admin_can_invite_an_operator(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/operator-invites", [
                'email' => 'helper@example.test',
                'can_ticketing' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'helper@example.test')
            ->assertJsonPath('data.can_ticketing', true)
            ->assertJsonPath('data.can_scheduling', false);

        $this->assertDatabaseHas('event_operator_invites', [
            'event_id' => $event->id,
            'email' => 'helper@example.test',
            'status' => 'pending',
        ]);

        Mail::assertQueued(EventOperatorInvitedMail::class);
    }

    public function test_invite_rejects_zero_categories(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/operator-invites", [
                'email' => 'helper@example.test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('can_ticketing');

        $this->assertSame(0, EventOperatorInvite::count());
    }

    public function test_org_level_operator_member_cannot_invite(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $gateOperator = User::factory()->create();
        $org->members()->create(['user_id' => $gateOperator->id, 'role' => 'operator']);

        $this->actingAs($gateOperator, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/operator-invites", [
                'email' => 'helper@example.test',
                'can_ticketing' => true,
            ])
            ->assertStatus(403);
    }

    public function test_admin_of_another_org_cannot_invite_into_this_event(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $otherOwner = User::factory()->create();
        $otherOrg = $this->org($otherOwner);

        $response = $this->actingAs($otherOwner, 'api')
            ->postJson("/api/v1/organizations/{$otherOrg->id}/events/{$event->id}/operator-invites", [
                'email' => 'helper@example.test',
                'can_ticketing' => true,
            ]);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame(0, EventOperatorInvite::count());
    }

    // ---- Accept ----

    private function pendingInvite(Event $event, User $invitedBy, string $email = 'helper@example.test', array $perm = []): EventOperatorInvite
    {
        return $event->operatorInvites()->create([
            'email' => $email,
            'can_ticketing' => $perm['can_ticketing'] ?? true,
            'can_scheduling' => $perm['can_scheduling'] ?? false,
            'can_registration' => $perm['can_registration'] ?? false,
            'can_documents' => $perm['can_documents'] ?? false,
            'status' => EventOperatorInvite::STATUS_PENDING,
            'token' => str_repeat('a', 64),
            'expires_at' => now()->addDays(7),
            'invited_by' => $invitedBy->id,
        ]);
    }

    public function test_accept_requires_matching_email(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner, 'helper@example.test');

        $wrongUser = User::factory()->create(['email' => 'someoneelse@example.test']);

        $this->actingAs($wrongUser, 'api')
            ->postJson("/api/v1/invites/{$invite->token}/accept")
            ->assertStatus(403);

        $this->assertDatabaseMissing('event_operators', ['event_id' => $event->id]);
    }

    public function test_accept_requires_auth(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner, 'helper@example.test');

        $this->postJson("/api/v1/invites/{$invite->token}/accept")
            ->assertStatus(401);
    }

    public function test_accept_rejects_expired_token(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner, 'helper@example.test');
        $invite->update(['expires_at' => now()->subDay()]);

        $user = User::factory()->create(['email' => 'helper@example.test']);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/invites/{$invite->token}/accept")
            ->assertStatus(422);

        $this->assertDatabaseMissing('event_operators', ['event_id' => $event->id]);
    }

    public function test_accept_rejects_revoked_token(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner, 'helper@example.test');
        $invite->update(['status' => EventOperatorInvite::STATUS_REVOKED]);

        $user = User::factory()->create(['email' => 'helper@example.test']);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/invites/{$invite->token}/accept")
            ->assertStatus(422);
    }

    public function test_accept_rejects_replay_after_already_accepted(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner, 'helper@example.test', ['can_ticketing' => true]);

        $user = User::factory()->create(['email' => 'helper@example.test']);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/invites/{$invite->token}/accept")
            ->assertOk();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/invites/{$invite->token}/accept")
            ->assertStatus(422);

        $this->assertSame(1, EventOperator::where('event_id', $event->id)->where('user_id', $user->id)->count());
    }

    public function test_accept_creates_operator_with_exact_invited_permissions(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner, 'helper@example.test', [
            'can_ticketing' => true,
            'can_scheduling' => false,
            'can_registration' => true,
            'can_documents' => false,
        ]);

        $user = User::factory()->create(['email' => 'helper@example.test']);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/invites/{$invite->token}/accept")
            ->assertOk()
            ->assertJsonPath('data.event_id', $event->id);

        $this->assertDatabaseHas('event_operators', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'can_ticketing' => true,
            'can_scheduling' => false,
            'can_registration' => true,
            'can_documents' => false,
        ]);
    }

    // ---- Resend ----

    public function test_resend_rotates_token_and_invalidates_the_old_one(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner);
        $oldToken = $invite->token;

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/operator-invites/{$invite->id}/resend")
            ->assertOk();

        $invite->refresh();
        $this->assertNotSame($oldToken, $invite->token);

        // The old token no longer resolves to anything usable.
        $this->getJson("/api/v1/invites/{$oldToken}")->assertStatus(404);
        $this->getJson("/api/v1/invites/{$invite->token}")->assertOk();
    }

    public function test_resend_rejects_a_non_pending_invite(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner);
        $invite->update(['status' => EventOperatorInvite::STATUS_REVOKED]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/operator-invites/{$invite->id}/resend")
            ->assertStatus(422);
    }

    // ---- Revoke ----

    public function test_a_pending_invite_can_be_revoked(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner);

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/operator-invites/{$invite->id}")
            ->assertOk();

        $this->assertSame(EventOperatorInvite::STATUS_REVOKED, $invite->fresh()->status);
    }

    public function test_an_already_accepted_invite_cannot_be_revoked(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner);
        $invite->update(['status' => EventOperatorInvite::STATUS_ACCEPTED]);

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/operator-invites/{$invite->id}")
            ->assertStatus(422);
    }

    // ---- Gating per category ----

    private function makeOperator(Event $event, User $user, array $perm): EventOperator
    {
        return $event->operators()->create([
            'user_id' => $user->id,
            'can_ticketing' => $perm['can_ticketing'] ?? false,
            'can_scheduling' => $perm['can_scheduling'] ?? false,
            'can_registration' => $perm['can_registration'] ?? false,
            'can_documents' => $perm['can_documents'] ?? false,
        ]);
    }

    public function test_ticketing_only_operator_can_reach_ticketing_and_nothing_else(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $user = User::factory()->create();
        $this->makeOperator($event, $user, ['can_ticketing' => true]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-categories", [
                'name' => 'Reguler',
                'price' => 10000,
            ])
            ->assertCreated();

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations")
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/x/schedule")
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel")
            ->assertStatus(403);
    }

    public function test_registration_only_operator_can_reach_registration_and_nothing_else(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $user = User::factory()->create();
        $this->makeOperator($event, $user, ['can_registration' => true]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations")
            ->assertOk();

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/ticket-categories")
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/x/schedule")
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel")
            ->assertStatus(403);
    }

    // ---- Money & admin invariants ----

    public function test_operator_with_all_four_categories_still_cannot_reach_money_or_admin_surfaces(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $user = User::factory()->create();
        $this->makeOperator($event, $user, $this->fullAllCategories());

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/wallet")
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/bank-accounts")
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/withdrawals")
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/plan-orders")
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/exports/registrations")
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events", [
                'name' => 'New Event',
                'sport_type' => 'football',
            ])
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}", ['name' => 'Renamed'])
            ->assertStatus(403);
    }

    /**
     * `matches/{match}/confirm` is the single most sensitive route an
     * operator must never reach, named explicitly so a regression here is
     * easy to find: signing a result off is what makes it count, and an
     * operator with every category granted records results — they never
     * ratify them.
     */
    public function test_operator_with_all_four_categories_cannot_confirm_a_match_result(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league', 'sort_order' => 0,
        ]);

        $home = $event->teams()->create(['category_id' => $category->id, 'name' => 'Home', 'status' => 'approved']);
        $away = $event->teams()->create(['category_id' => $category->id, 'name' => 'Away', 'status' => 'approved']);

        $match = $event->matches()->create([
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'round' => 1,
            'order' => 1,
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 1,
        ]);

        $user = User::factory()->create();
        $this->makeOperator($event, $user, $this->fullAllCategories());

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}/confirm", ['confirmed' => true])
            ->assertStatus(403);

        $this->assertNull($match->fresh()->confirmed_at);
    }

    private function fullAllCategories(): array
    {
        return [
            'can_ticketing' => true,
            'can_scheduling' => true,
            'can_registration' => true,
            'can_documents' => true,
        ];
    }

    // ---- Event detail: read-only for every operator, never writable ----

    /**
     * `GET events/{event}` (show) is deliberately outside every
     * `event.perm.*` category — see the route comment in api.php. An
     * operator holding just ONE category still has to render the event's
     * own name/status/dates for the page header before the category check
     * for whichever sub-page they are on even runs. A single-category
     * operator is the sharpest case: it proves the route is not accidentally
     * gated on `ticketing` (or whichever category happened to be granted)
     * rather than on `event.access` alone.
     */
    public function test_single_category_operator_can_read_event_detail(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $user = User::factory()->create();
        $this->makeOperator($event, $user, ['can_ticketing' => true]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.name', $event->name);
    }

    /**
     * The other half of the same invariant: read access to the event's own
     * detail must NOT widen into write access. `update`/`destroy` stay on
     * the plain `tenant` route, which refuses an operator outright (no
     * `organization_members` row at all) before the controller ever runs —
     * so this is also a regression guard against someone "fixing" the 403
     * by accidentally routing update/destroy through `event.access` too.
     */
    public function test_operator_with_all_categories_still_cannot_update_or_delete_the_event(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $user = User::factory()->create();
        $this->makeOperator($event, $user, $this->fullAllCategories());

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertStatus(403);

        $this->assertSame($event->name, $event->fresh()->name);
    }

    /**
     * An org member's access to `show` must not narrow just because the
     * route moved under `event.access` — an owner/admin always resolves as
     * the unrestricted branch there, identical to what plain `tenant` gave.
     */
    public function test_org_member_can_still_read_event_detail(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $event->id);
    }

    // ---- Cross-event isolation ----

    public function test_operator_of_one_event_cannot_reach_a_sibling_event_in_the_same_org(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $eventA = $this->event($org);
        $eventB = $this->eventOn($org);

        $user = User::factory()->create();
        $this->makeOperator($eventA, $user, ['can_registration' => true]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$eventA->id}/registrations")
            ->assertOk();

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$eventB->id}/registrations");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_operator_of_an_event_cannot_reach_an_event_in_another_org(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $otherOwner = User::factory()->create();
        $otherOrg = $this->org($otherOwner);
        $otherEvent = $this->eventOn($otherOrg);

        $user = User::factory()->create();
        $this->makeOperator($event, $user, ['can_registration' => true]);

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/v1/organizations/{$otherOrg->id}/events/{$otherEvent->id}/registrations");

        $this->assertContains($response->status(), [403, 404]);
    }

    // ---- Management endpoint cross-org (404, not 403) ----

    public function test_admin_of_another_org_gets_404_patching_a_foreign_operator(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $user = User::factory()->create();
        $operator = $this->makeOperator($event, $user, ['can_ticketing' => true, 'can_registration' => true]);

        $otherOwner = User::factory()->create();
        $otherOrg = $this->org($otherOwner);

        $this->actingAs($otherOwner, 'api')
            ->patchJson("/api/v1/organizations/{$otherOrg->id}/operators/{$operator->id}", ['can_documents' => true])
            ->assertStatus(404);
    }

    public function test_admin_of_another_org_gets_404_deleting_a_foreign_operator(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $user = User::factory()->create();
        $operator = $this->makeOperator($event, $user, ['can_ticketing' => true]);

        $otherOwner = User::factory()->create();
        $otherOrg = $this->org($otherOwner);

        $this->actingAs($otherOwner, 'api')
            ->deleteJson("/api/v1/organizations/{$otherOrg->id}/operators/{$operator->id}")
            ->assertStatus(404);
    }

    public function test_admin_of_another_org_gets_404_resending_a_foreign_invite(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner);

        $otherOwner = User::factory()->create();
        $otherOrg = $this->org($otherOwner);

        $this->actingAs($otherOwner, 'api')
            ->postJson("/api/v1/organizations/{$otherOrg->id}/operator-invites/{$invite->id}/resend")
            ->assertStatus(404);
    }

    public function test_admin_of_another_org_gets_404_revoking_a_foreign_invite(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $invite = $this->pendingInvite($event, $owner);

        $otherOwner = User::factory()->create();
        $otherOrg = $this->org($otherOwner);

        $this->actingAs($otherOwner, 'api')
            ->deleteJson("/api/v1/organizations/{$otherOrg->id}/operator-invites/{$invite->id}")
            ->assertStatus(404);
    }

    // ---- Partial PATCH regression (Temuan 1) ----

    public function test_partial_patch_with_a_single_field_succeeds_and_leaves_the_rest_unchanged(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $user = User::factory()->create();
        $operator = $this->makeOperator($event, $user, [
            'can_ticketing' => true,
            'can_scheduling' => false,
            'can_registration' => true,
            'can_documents' => false,
        ]);

        // Before the fix: UpdateEventOperatorRequest::withValidator() read
        // `->can_ticketing` off a bare route-parameter STRING (the route is
        // not model-bound) and crashed 500 on any payload that omits a field.
        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/operators/{$operator->id}", [
                'can_documents' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.can_ticketing', true)
            ->assertJsonPath('data.can_scheduling', false)
            ->assertJsonPath('data.can_registration', true)
            ->assertJsonPath('data.can_documents', true);

        $this->assertDatabaseHas('event_operators', [
            'id' => $operator->id,
            'can_ticketing' => true,
            'can_scheduling' => false,
            'can_registration' => true,
            'can_documents' => true,
        ]);
    }

    public function test_partial_patch_that_would_leave_zero_categories_is_refused(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $user = User::factory()->create();
        $operator = $this->makeOperator($event, $user, ['can_ticketing' => true]);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/operators/{$operator->id}", [
                'can_ticketing' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('can_ticketing');

        $this->assertTrue($operator->fresh()->can_ticketing);
    }

    // ---- Cascade on event deletion ----

    public function test_deleting_a_draft_event_cascades_operators_and_invites(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'Draft Cup',
            'slug' => 'draft-cup-'.uniqid(),
            'sport_type' => 'football',
            'status' => 'draft',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
        ]);

        $user = User::factory()->create();
        $operator = $this->makeOperator($event, $user, ['can_ticketing' => true]);
        $invite = $this->pendingInvite($event, $owner, 'pending@example.test');

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/organizations/{$org->id}/events/{$event->id}")
            ->assertOk();

        $this->assertDatabaseMissing('event_operators', ['id' => $operator->id]);
        $this->assertDatabaseMissing('event_operator_invites', ['id' => $invite->id]);
    }
}
