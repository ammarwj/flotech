<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * Catatan bebas organizer untuk satu laga, dan yang menjaganya tetap satu field
 * dengan satu pintu tulis.
 *
 * Tiga hal yang mudah rusak tanpa terlihat, dan tiap-tiapnya diuji dengan
 * **membandingkan** alih-alih satu assert tunggal:
 *
 *  - whitespace yang tersimpan sebagai catatan "terisi" — halaman publik lalu
 *    merender strip kosong di bawah nama tim;
 *  - `updateResult` yang ikut menghapus catatan karena payload-nya tidak
 *    membawanya (pintu tulis kedua, bentuk bug yang sama dengan dua pembaca
 *    `stage`);
 *  - rute yang diam-diam pindah ke `org.admin`, yang mengeluarkan operator —
 *    justru orang yang mengetik "dipindah ke lapangan 2".
 */
class MatchNotesTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner): Organization
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0]);

        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id, 'plan_id' => $plan->id,
        ]);
    }

    private function leagueEvent(Organization $org): Event
    {
        $event = $org->events()->create([
            'plan_id' => $this->planId(),
            'name' => 'League Cup',
            'slug' => 'league-cup-'.uniqid(),
            'sport_type' => 'mini_soccer',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        foreach (range(1, 2) as $i) {
            $event->teams()->create([
                'category_id' => $category->id,
                'name' => 'Team '.$i,
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
            ]);
        }

        return $event->load('categories');
    }

    /** One scheduled fixture between the event's two approved teams. */
    private function fixture(Event $event): GameMatch
    {
        [$home, $away] = $event->teams()->orderBy('name')->pluck('id')->all();

        return $event->matches()->create([
            'category_id' => $event->categories->first()->id,
            'round' => 1,
            'order' => 1,
            'home_team_id' => $home,
            'away_team_id' => $away,
            'status' => 'scheduled',
            'scheduled_at' => '2026-08-05T08:00:00Z',
            'venue' => 'Lapangan A',
        ]);
    }

    private function scheduleUrl(Organization $org, GameMatch $match): string
    {
        return "/api/v1/organizations/{$org->id}/matches/{$match->id}/schedule";
    }

    public function test_a_note_is_stored_and_published_to_the_public_schedule(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org);
        $match = $this->fixture($event);

        $this->actingAs($user, 'api')
            ->patchJson($this->scheduleUrl($org, $match), [
                'scheduled_at' => '2026-08-05T08:00:00Z',
                'venue' => 'Lapangan B',
                'notes' => 'Laga dipindah ke lapangan 2 karena hujan.',
            ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Laga dipindah ke lapangan 2 karena hujan.');

        $this->assertSame('Laga dipindah ke lapangan 2 karena hujan.', $match->fresh()->notes);

        // The public schedule reads the same resource, so publishing it once is
        // what makes the note reach visitors at all.
        $category = $event->categories->first();
        $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}/categories/{$category->slug}/matches")
            ->assertOk()
            ->assertJsonPath('data.0.notes', 'Laga dipindah ke lapangan 2 karena hujan.');
    }

    /**
     * Compared against a real note in the same test: asserting only that a typed
     * note survives would still pass if whitespace were stored verbatim, and the
     * public card renders its strip on any truthy value.
     */
    public function test_blank_and_whitespace_notes_are_stored_as_null(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org);
        $match = $this->fixture($event);

        $payload = ['scheduled_at' => '2026-08-05T08:00:00Z', 'venue' => 'Lapangan A'];

        $this->actingAs($user, 'api')
            ->patchJson($this->scheduleUrl($org, $match), [...$payload, 'notes' => 'Ada catatan'])
            ->assertOk();
        $this->assertSame('Ada catatan', $match->fresh()->notes);

        // Whitespace only — trimmed to nothing, so there is no note.
        $this->actingAs($user, 'api')
            ->patchJson($this->scheduleUrl($org, $match), [...$payload, 'notes' => '   '])
            ->assertOk()
            ->assertJsonPath('data.notes', null);
        $this->assertNull($match->fresh()->notes);

        // And an omitted key clears it too — full-replace, exactly like `venue`.
        $this->actingAs($user, 'api')
            ->patchJson($this->scheduleUrl($org, $match), [...$payload, 'notes' => 'Kembali terisi'])
            ->assertOk();
        $this->assertSame('Kembali terisi', $match->fresh()->notes);

        $this->actingAs($user, 'api')
            ->patchJson($this->scheduleUrl($org, $match), $payload)
            ->assertOk()
            ->assertJsonPath('data.notes', null);
        $this->assertNull($match->fresh()->notes);
    }

    /**
     * The one that proves there is a single write door. `MatchResultService`
     * validates `scheduled_at`/`venue` and writes its payload as-is, so a note
     * it never mentions has to come through untouched. Asserting "the score was
     * saved" alone would stay green while every result entry wiped the note.
     */
    public function test_saving_a_result_leaves_the_note_intact(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org);
        $match = $this->fixture($event);

        $this->actingAs($user, 'api')
            ->patchJson($this->scheduleUrl($org, $match), [
                'scheduled_at' => '2026-08-05T08:00:00Z',
                'venue' => 'Lapangan A',
                'notes' => 'Tim tamu datang terlambat 20 menit.',
            ])
            ->assertOk();

        $before = $match->fresh()->notes;

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/organizations/{$org->id}/matches/{$match->id}", [
                'status' => 'finished',
                'home_score' => 3,
                'away_score' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.home_score', 3)
            ->assertJsonPath('data.notes', $before);

        $fresh = $match->fresh();
        $this->assertSame($before, $fresh->notes);
        $this->assertSame(3, $fresh->home_score);
    }

    public function test_a_note_longer_than_the_cap_is_rejected(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->leagueEvent($org);
        $match = $this->fixture($event);

        $this->actingAs($user, 'api')
            ->patchJson($this->scheduleUrl($org, $match), [
                'scheduled_at' => '2026-08-05T08:00:00Z',
                'notes' => str_repeat('a', 501),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');

        $this->assertNull($match->fresh()->notes);
    }

    /**
     * The route sits in the plain `tenant` group, not behind `org.admin`: the
     * operator running the courts is the person who writes "moved to court 2".
     * Compared against an outsider, because asserting the owner succeeds would
     * pass identically under `org.admin`.
     */
    public function test_an_operator_may_write_a_note_but_an_outsider_may_not(): void
    {
        $owner = User::factory()->create();
        $org = $this->org($owner);
        $event = $this->leagueEvent($org);
        $match = $this->fixture($event);

        $operator = User::factory()->create();
        $org->members()->create(['user_id' => $operator->id, 'role' => 'operator']);

        $this->actingAs($operator, 'api')
            ->patchJson($this->scheduleUrl($org, $match), [
                'scheduled_at' => '2026-08-05T08:00:00Z',
                'notes' => 'Dipindah ke lapangan 2.',
            ])
            ->assertOk();
        $this->assertSame('Dipindah ke lapangan 2.', $match->fresh()->notes);

        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'api')
            ->patchJson($this->scheduleUrl($org, $match), [
                'scheduled_at' => '2026-08-05T08:00:00Z',
                'notes' => 'Catatan orang luar.',
            ])
            ->assertForbidden();
        $this->assertSame('Dipindah ke lapangan 2.', $match->fresh()->notes);
    }
}
