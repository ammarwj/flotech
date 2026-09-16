<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Referees and match staff — the people an event needs who belong to no team.
 *
 * Every case compares two states. The sync contract in particular cannot be
 * proven by asserting the data is there afterwards: a sync that deletes
 * everything and recreates it passes that, and takes player_match_stats-grade
 * damage with it on the tables that cascade.
 */
class EventPersonnelTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function org(User $owner): Organization
    {
        return $this->orgFor($owner);
    }

    private function event(Organization $org): Event
    {
        return $this->eventOn($org);
    }

    /** Put a file on the fake disk and return the URL a row would store. */
    private function file(string $key): string
    {
        Storage::disk('public')->put($key, 'x');

        return Storage::disk('public')->url($key);
    }

    private function keyOf(string $url): string
    {
        return ltrim(str_replace(Storage::disk('public')->url(''), '', $url), '/');
    }

    /**
     * Fire the purge jobs the request deferred with afterCommit(). Copied from
     * MediaCleanupTest for the reason spelled out there: RefreshDatabase rolls
     * its transaction back rather than committing, so nothing fires on its own.
     */
    private function commitDeferredJobs(): void
    {
        $this->app->make('db.transactions')
            ->callbackApplicableTransactions()
            ->each
            ->executeCallbacks();
    }

    public function test_sync_keeps_edited_rows_and_drops_omitted_ones(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel", [
                'personnel' => [
                    ['full_name' => 'Wasit A', 'kind' => 'referee', 'role_label' => 'Wasit Utama'],
                    ['full_name' => 'Wasit B', 'kind' => 'referee'],
                    ['full_name' => 'Staf C', 'kind' => 'staff', 'role_label' => 'Panitia Lapangan'],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'data');

        [$a, $b, $c] = $event->personnel()->get()->all();

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel", [
                'personnel' => [
                    ['id' => $a->id, 'full_name' => 'Wasit A Direvisi', 'kind' => 'referee', 'role_label' => 'Wasit Utama'],
                    ['id' => $c->id, 'full_name' => 'Staf C', 'kind' => 'staff', 'role_label' => 'Panitia Lapangan'],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // The comparison that matters: the surviving rows keep their ids while
        // their contents change. A delete-all-then-recreate sync passes an
        // assertion that the two names are simply present.
        $this->assertSame([$a->id, $c->id], $event->personnel()->pluck('id')->all());
        $this->assertSame('Wasit A Direvisi', $a->fresh()->full_name);
        $this->assertDatabaseMissing('event_personnel', ['id' => $b->id]);
    }

    public function test_personnel_are_not_part_of_the_roster(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $category = $event->categories()->create([
            'name' => 'Umum', 'slug' => 'umum', 'tournament_format' => 'league', 'sort_order' => 0,
        ]);

        $team = $event->teams()->create([
            'category_id' => $category->id,
            'name' => 'Team A',
            'status' => 'approved',
        ]);

        $team->players()->create(['full_name' => 'Pemain Satu']);
        $before = $team->players()->count();

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel", [
                'personnel' => [
                    ['full_name' => 'Wasit A', 'kind' => 'referee'],
                    ['full_name' => 'Staf B', 'kind' => 'staff'],
                ],
            ])
            ->assertOk();

        // Both numbers, because either one alone is satisfied by a referee that
        // landed in `players`: the squad count would be 3 and look plausible.
        $this->assertSame($before, $team->players()->count());
        $this->assertSame(2, $event->personnel()->count());
    }

    public function test_dropping_a_personnel_row_purges_only_its_photo(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $dropped = $this->file('personnel/dropped.webp');
        $kept = $this->file('personnel/kept.webp');

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel", [
                'personnel' => [
                    ['full_name' => 'Wasit A', 'kind' => 'referee', 'photo_url' => $dropped],
                    ['full_name' => 'Wasit B', 'kind' => 'referee', 'photo_url' => $kept],
                ],
            ])
            ->assertOk();

        $keep = $event->personnel()->where('full_name', 'Wasit B')->firstOrFail();

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel", [
                'personnel' => [
                    ['id' => $keep->id, 'full_name' => 'Wasit B', 'kind' => 'referee', 'photo_url' => $kept],
                ],
            ])
            ->assertOk();

        $this->commitDeferredJobs();

        // The pair: a purge that is too greedy passes the first assertion alone.
        Storage::disk('public')->assertMissing($this->keyOf($dropped));
        Storage::disk('public')->assertExists($this->keyOf($kept));
    }

    public function test_an_empty_role_label_falls_back_only_when_read(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel", [
                'personnel' => [
                    ['full_name' => 'Wasit A', 'kind' => 'referee'],
                    ['full_name' => 'Staf B', 'kind' => 'staff', 'role_label' => 'Panitia Lapangan'],
                ],
            ])
            ->assertOk()
            // Compared as a pair: the fallback fills the display line, but the
            // stored column stays null. A fallback written at save time passes
            // the role_display half on its own, and then the editor can never
            // show the field as empty again.
            ->assertJsonPath('data.0.role_label', null)
            ->assertJsonPath('data.0.role_display', 'Wasit')
            ->assertJsonPath('data.1.role_label', 'Panitia Lapangan')
            ->assertJsonPath('data.1.role_display', 'Panitia Lapangan');

        $this->assertDatabaseHas('event_personnel', ['full_name' => 'Wasit A', 'role_label' => null]);
    }

    public function test_another_organization_cannot_read_or_write_the_list(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->event($org);

        $event->personnel()->create(['full_name' => 'Wasit A', 'kind' => 'referee', 'sort_order' => 0]);

        $intruder = User::factory()->create();
        $otherOrg = $this->org($intruder);

        // The event id is real; only the organization in the path is wrong.
        $this->actingAs($intruder, 'api')
            ->getJson("/api/v1/organizations/{$otherOrg->id}/events/{$event->id}/personnel")
            ->assertNotFound();

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/organizations/{$org->id}/events/{$event->id}/personnel", [
                'personnel' => [['full_name' => 'Wasit A', 'kind' => 'pelatih']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('personnel.0.kind');

        $this->assertSame(0, $event->personnel()->count());
    }

    public function test_the_old_indonesian_kinds_are_refused_and_the_english_ones_accepted(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->event($org);
        $url = "/api/v1/organizations/{$org->id}/events/{$event->id}/personnel";

        // The pair is the point. A stale frontend still sending 'wasit' must be
        // refused loudly rather than storing a value no middleware recognises —
        // and asserting only the 422 would pass just as well if the rule had
        // been tightened to refuse everything.
        $this->actingAs($user, 'api')
            ->putJson($url, ['personnel' => [['full_name' => 'Wasit A', 'kind' => 'wasit']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('personnel.0.kind');

        $this->actingAs($user, 'api')
            ->putJson($url, ['personnel' => [['full_name' => 'Wasit A', 'kind' => 'referee']]])
            ->assertOk()
            // The stored vocabulary changed; the printed one did not.
            ->assertJsonPath('data.0.role_display', 'Wasit');
    }
}
