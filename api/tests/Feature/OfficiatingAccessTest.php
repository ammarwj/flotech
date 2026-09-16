<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventPersonnel;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\PersonnelInvited;
use App\Services\EventPersonnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Task accounts: a name on an ID card becomes someone who can log in and work.
 *
 * Three things here can only be proven by comparison, and each has a way of
 * passing while broken:
 *
 *  - The door. Asserting the officiating route answers 200 says nothing about
 *    whether the crew is *kept out* of the organizer API, which is the entire
 *    reason these accounts are not organization_members. Both halves, same
 *    account, same event.
 *  - Idempotence. Asserting "an invite was sent" passes just as well when every
 *    save of the personnel form re-mails everyone their password. Two identical
 *    PUTs, count the mails.
 *  - Account survival. Asserting the dropped referee now gets a 403 passes just
 *    as well if the sync deleted their `users` row — taking their assignments on
 *    every other event with it. Count users before and after.
 */
class OfficiatingAccessTest extends TestCase
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

    private function personnelUrl(Organization $org, Event $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event->id}/personnel";
    }

    /**
     * Put one crew member on an event and hand back their freshly provisioned
     * account. Goes through the service rather than the endpoint so that tests
     * about the *door* don't depend on the endpoint being right.
     *
     * Once per event: sync() is a full-list write, so a second call replaces
     * the crew rather than adding to it.
     */
    private function crew(Event $event, string $kind, string $email = 'crew@example.test'): User
    {
        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Petugas', 'kind' => $kind, 'email' => $email],
        ]);

        return User::where('email', $email)->firstOrFail();
    }

    /** Rotated, so `password.rotated` is not what a 403 in these tests means. */
    private function rotated(User $user): User
    {
        $user->forceFill(['must_change_password' => false])->save();

        return $user->fresh();
    }

    public function test_a_crew_account_reaches_officiating_but_not_the_organizer_api(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $referee = $this->rotated($this->crew($event, 'referee'));

        // The pair is the point. The officiating tier answering 200 proves the
        // account works; the organizer tier answering 403 proves that working
        // did not come with the wallet, the billing pages and event deletion
        // attached. Either assertion alone is consistent with the other being
        // wrong in the most dangerous direction.
        $this->actingAs($referee, 'api')
            ->getJson("/api/v1/officiating/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.assignment.kind', 'referee');

        $this->actingAs($referee, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel")
            ->assertStatus(403);
    }

    public function test_crew_of_one_event_cannot_read_another(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $mine = $this->event($org);
        $theirs = $this->event($org);
        $referee = $this->rotated($this->crew($mine, 'referee'));

        // Same organizer, same account, two events: nothing but the personnel
        // row can account for the difference.
        $this->actingAs($referee, 'api')
            ->getJson("/api/v1/officiating/events/{$mine->id}")
            ->assertOk();

        $this->actingAs($referee, 'api')
            ->getJson("/api/v1/officiating/events/{$theirs->id}")
            ->assertStatus(403);
    }

    public function test_the_unrotated_default_password_cannot_open_the_duty_surface(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $staff = $this->crew($event, 'staff');

        // Straight out of the invite: flagged, and refused.
        $this->assertTrue($staff->must_change_password);
        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/officiating/events/{$event->id}")
            ->assertStatus(403);

        // The only thing that changed is the flag. Same account, same event.
        $this->actingAs($this->rotated($staff), 'api')
            ->getJson("/api/v1/officiating/events/{$event->id}")
            ->assertOk();
    }

    public function test_an_identical_second_save_sends_no_second_invite(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $url = $this->personnelUrl($org, $event);

        $payload = fn (?string $id) => ['personnel' => [array_filter([
            'id' => $id,
            'full_name' => 'Wasit A',
            'kind' => 'referee',
            'email' => 'wasit@example.test',
        ])]];

        $first = $this->actingAs($owner, 'api')->putJson($url, $payload(null))->assertOk();
        $rowId = $first->json('data.0.id');

        // The form round-trips the row it was given, so the second save carries
        // an id — exactly what the organizer's browser sends when they fix a
        // typo three rows down.
        $this->actingAs($owner, 'api')->putJson($url, $payload($rowId))->assertOk();

        // One, not two. Asserting "an invite was sent" would pass either way,
        // and the failure mode it misses is mailing everyone their password
        // again on every single save.
        Notification::assertSentToTimes(
            User::where('email', 'wasit@example.test')->firstOrFail(),
            PersonnelInvited::class,
            1,
        );
    }

    public function test_dropping_a_crew_row_closes_access_without_deleting_the_account(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $other = $this->event($org);
        $url = $this->personnelUrl($org, $event);

        $this->actingAs($owner, 'api')->putJson($url, [
            'personnel' => [
                ['full_name' => 'Wasit A', 'kind' => 'referee', 'email' => 'wasit@example.test'],
            ],
        ])->assertOk();

        $referee = $this->rotated(User::where('email', 'wasit@example.test')->firstOrFail());

        // Same person, crew on a second event. This is what the account would
        // take down with it if the sync deleted it.
        app(EventPersonnelService::class)->sync($other, [
            ['full_name' => 'Wasit A', 'kind' => 'referee', 'email' => 'wasit@example.test'],
        ]);

        $before = User::count();

        // Drop them from the first event by omitting the row.
        $this->actingAs($owner, 'api')->putJson($url, ['personnel' => []])->assertOk();

        // Both halves. The 403 alone is satisfied by an account that no longer
        // exists; the unchanged count alone says nothing about access.
        $this->assertSame($before, User::count());
        $this->actingAs($referee, 'api')
            ->getJson("/api/v1/officiating/events/{$event->id}")
            ->assertStatus(403);
        $this->actingAs($referee, 'api')
            ->getJson("/api/v1/officiating/events/{$other->id}")
            ->assertOk();
    }

    public function test_linking_an_existing_account_never_touches_its_password(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $existing = User::factory()->create(['email' => 'manajer@example.test']);
        $existing->forceFill(['password' => 'rahasiaku99'])->save();
        $before = $existing->fresh()->password;

        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Manajer', 'kind' => 'staff', 'email' => 'manajer@example.test'],
        ]);

        $after = $existing->fresh();

        // Compare the stored hash, not a login attempt: a flow that overwrote
        // the password with the default and then happened to mail it would look
        // identical from the outside until somebody read their inbox. Typing a
        // stranger's address into a personnel row must not be a takeover.
        $this->assertSame($before, $after->password);
        $this->assertTrue(Hash::check('rahasiaku99', $after->password));
        $this->assertFalse($after->must_change_password);

        // And the copy differs where it counts: no password in this one.
        Notification::assertSentTo(
            $after,
            PersonnelInvited::class,
            fn (PersonnelInvited $n) => $n->withPassword === false && $n->password === null,
        );
    }

    public function test_a_created_account_gets_the_default_password_and_the_flag(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Wasit A', 'kind' => 'referee', 'email' => 'baru@example.test'],
        ]);

        $created = User::where('email', 'baru@example.test')->firstOrFail();

        // The cast is 'hashed', so writing the plaintext is what stores a usable
        // hash — Hash::make() here would store a hash of a hash and lock the
        // account out of its own invitation. Checking the literal is what
        // catches that; asserting the row exists would not.
        $this->assertTrue(Hash::check(EventPersonnelService::DEFAULT_PASSWORD, $created->password));
        $this->assertTrue($created->must_change_password);
        $this->assertSame('officiating', $created->default_mode);
        // The invitation *is* the verification: the password can only be read
        // out of that inbox.
        $this->assertNotNull($created->email_verified_at);

        Notification::assertSentTo(
            $created,
            PersonnelInvited::class,
            fn (PersonnelInvited $n) => $n->withPassword === true
                && $n->password === EventPersonnelService::DEFAULT_PASSWORD,
        );
    }

    public function test_clearing_the_email_unlinks_the_account_and_sends_nothing(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);
        $url = $this->personnelUrl($org, $event);

        $this->actingAs($owner, 'api')->putJson($url, [
            'personnel' => [
                ['full_name' => 'Wasit A', 'kind' => 'referee', 'email' => 'wasit@example.test'],
            ],
        ])->assertOk();

        $row = EventPersonnel::where('event_id', $event->id)->firstOrFail();
        $referee = $this->rotated(User::where('email', 'wasit@example.test')->firstOrFail());
        $this->assertNotNull($row->user_id);

        $this->actingAs($owner, 'api')->putJson($url, [
            'personnel' => [
                ['id' => $row->id, 'full_name' => 'Wasit A', 'kind' => 'referee', 'email' => null],
            ],
        ])->assertOk()->assertJsonPath('data.0.has_account', false);

        // Row kept, link gone, access gone — and the account still standing,
        // for the same reason as the dropped-row case above.
        $this->assertNull($row->fresh()->user_id);
        $this->assertNotNull($referee->fresh());
        $this->actingAs($referee, 'api')
            ->getJson("/api/v1/officiating/events/{$event->id}")
            ->assertStatus(403);
    }

    public function test_role_middleware_separates_staff_from_referee(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        // One sync, both rows. `crew()` can't be called twice on the same event
        // — the full-list contract would read the second call as "the crew is
        // now just this one person" and delete the first.
        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Staf', 'kind' => 'staff', 'email' => 'staf@example.test'],
            ['full_name' => 'Wasit', 'kind' => 'referee', 'email' => 'wasit@example.test'],
        ]);

        $staff = $this->rotated(User::where('email', 'staf@example.test')->firstOrFail());
        $referee = $this->rotated(User::where('email', 'wasit@example.test')->firstOrFail());

        // Both are crew, so both clear the door. The role middlewares are what
        // has to tell them apart afterwards — and until phase 4 and 6 hang
        // routes on them, the thing worth proving is that the row each one
        // carries says the right kind.
        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/officiating/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.assignment.kind', 'staff');

        $this->actingAs($referee, 'api')
            ->getJson("/api/v1/officiating/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.assignment.kind', 'referee');
    }

    public function test_me_says_whether_a_crew_account_is_anything_else(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        app(EventPersonnelService::class)->sync($event, [
            ['full_name' => 'Wasit', 'kind' => 'referee', 'email' => 'wasit@example.test'],
            ['full_name' => 'Staf', 'kind' => 'staff', 'email' => $owner->email],
        ]);

        $referee = $this->rotated(User::where('email', 'wasit@example.test')->firstOrFail());

        $me = fn (User $user) => $this->actingAs($user, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('data');

        $crewOnly = $me($referee);
        $bothHats = $me($owner->fresh());

        // The pair is what proves anything. `account_types` is published only
        // when its three relations are prepared, so an auth response that
        // forgets them omits the key — and the shell reads a missing key the
        // same way it reads an empty list: crew and nothing else. Asserting the
        // referee's `[]` alone passes in exactly that state, and the organizer
        // who was also put on the crew would silently lose their mode switcher.
        $this->assertSame([], $crewOnly['account_types']);
        $this->assertSame(['organizer'], $bothHats['account_types']);

        // Both are crew: the flag that routes them here is not what tells them
        // apart.
        $this->assertSame([$event->id], array_column($crewOnly['officiating'], 'event_id'));
        $this->assertSame([$event->id], array_column($bothHats['officiating'], 'event_id'));
    }

    public function test_the_event_list_carries_only_this_accounts_assignments(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = $this->org($owner);
        $mine = $this->event($org);
        $theirs = $this->event($org);

        $referee = $this->rotated($this->crew($mine, 'referee', 'wasit@example.test'));
        $this->crew($theirs, 'staff', 'staf@example.test');

        $ids = $this->actingAs($referee, 'api')
            ->getJson('/api/v1/officiating/events')
            ->assertOk()
            ->json('data.*.event_id');

        // Both events exist and both have crew; only one of them is this
        // account's. A list that returned everything would pass an "is my event
        // in there" assertion.
        $this->assertSame([$mine->id], $ids);
    }
}
