<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * `in_schedule`: a registration field whose answer is printed under the
 * entrant's name on a fixture card — the club a badminton pair represents.
 *
 * Every uji here **compares**, because each failure mode passes a one-sided
 * assert:
 *
 * - reading only the team's answers still prints tags for a squad event, and
 *   prints nothing for the tunggal/ganda categories the feature exists for
 *   (there the club is typed on the player row — `teams.name` is derived);
 * - a third flag that is really a reading of `is_public` still prints the field
 *   an organizer published, and prints the date of birth beside it too;
 * - an absent flag read as "on" still prints the right thing for an event that
 *   ticked one field, and leaks every answer for the events that ticked none.
 */
class ScheduleTagsTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    private function org(User $owner): Organization
    {
        return Organization::create([
            'name' => 'Org', 'slug' => 'org-'.uniqid(), 'owner_id' => $owner->id,
        ]);
    }

    /**
     * A badminton event whose form carries the given lists, with one `double`
     * category and one `team` category — the two shapes that differ on WHERE
     * the club is typed.
     */
    private function badmintonEvent(Organization $org, array $form): Event
    {
        $event = $this->eventOn($org, null, [
            'sport_type' => 'badminton',
            'status' => 'open',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-30',
            'registration_form' => $form,
        ]);

        foreach ([['Ganda Dewasa Putra', 'double'], ['Beregu Putra', 'team']] as [$name, $type]) {
            $event->categories()->create([
                'name' => $name,
                'slug' => str($name)->slug().'-'.uniqid(),
                'participant_type' => $type,
                'tournament_format' => 'league',
                'registration_fee' => 0,
                'sort_order' => 0,
            ]);
        }

        return $event->load('categories');
    }

    /**
     * One fixture between two entries in the named category, each entry built
     * from `[team custom_fields, [player custom_fields, ...]]`.
     */
    private function fixture(Event $event, string $category, array $home, array $away): GameMatch
    {
        $cat = $event->categories->firstWhere('name', $category);
        $ids = [];

        foreach ([['Home', $home], ['Away', $away]] as [$label, [$teamFields, $playerFields]]) {
            $team = $event->teams()->create([
                'category_id' => $cat->id,
                'name' => $label,
                'status' => 'approved',
                'contact_name' => 'PIC',
                'contact_phone' => '0800',
                'custom_fields' => $teamFields,
            ]);

            foreach ($playerFields as $i => $fields) {
                $team->players()->create([
                    'full_name' => $label.' P'.($i + 1),
                    'custom_fields' => $fields,
                ]);
            }

            $ids[] = $team->id;
        }

        return $cat->matches()->create([
            'event_id' => $event->id,
            'round' => 1,
            'order' => 1,
            'leg' => 1,
            'home_team_id' => $ids[0],
            'away_team_id' => $ids[1],
            'status' => 'scheduled',
        ]);
    }

    private function scheduleUrl(Organization $org, Event $event, string $category): string
    {
        $id = $event->categories->firstWhere('name', $category)->id;

        return "/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$id}/matches";
    }

    /** One field, ticked, asked of each player — the production shape. */
    private function playerClubForm(bool $inSchedule = true): array
    {
        return [
            'team_fields' => [],
            'player_fields' => [[
                'key' => 'nama_pb',
                'label' => 'Nama PB',
                'type' => 'short_text',
                'is_public' => true,
                'in_schedule' => $inSchedule,
            ]],
            'team_official_fields' => [],
            'team_documents' => [],
            'player_documents' => [],
            'team_official_documents' => [],
        ];
    }

    /**
     * The whole point of folding both lists into one line: a pair types its
     * club on the player row, a squad event asks the team, and a reader of only
     * one of the two answers right for exactly half the formats.
     */
    public function test_tags_come_from_the_player_rows_and_from_the_team_row(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $form = $this->playerClubForm();
        $form['team_fields'] = [[
            'key' => 'asal_klub',
            'label' => 'Asal Klub',
            'type' => 'short_text',
            'in_schedule' => true,
        ]];

        $event = $this->badmintonEvent($org, $form);

        // Pair: nothing on the team row, the club on both player rows.
        $this->fixture(
            $event,
            'Ganda Dewasa Putra',
            [[], [['nama_pb' => 'PB. Uncrown Medan'], ['nama_pb' => 'PB. Uncrown Medan']]],
            [[], [['nama_pb' => 'PB. Trimas'], []]],
        );

        $pair = $this->actingAs($user, 'api')
            ->getJson($this->scheduleUrl($org, $event, 'Ganda Dewasa Putra'))
            ->assertOk();

        // Both players named the same club; it prints once.
        $pair->assertJsonPath('data.0.home_team.tags', ['PB. Uncrown Medan']);
        // One blank player row contributes nothing rather than an empty tag.
        $pair->assertJsonPath('data.0.away_team.tags', ['PB. Trimas']);

        // Squad: the club on the team row, nothing on the players.
        $this->fixture(
            $event,
            'Beregu Putra',
            [['asal_klub' => 'PB. Permai'], [[], []]],
            [[], [[]]],
        );

        $squad = $this->actingAs($user, 'api')
            ->getJson($this->scheduleUrl($org, $event, 'Beregu Putra'))
            ->assertOk();

        $squad->assertJsonPath('data.0.home_team.tags', ['PB. Permai']);
        // An entry that answered neither list carries no tags at all — the card
        // renders nothing rather than a blank line.
        $squad->assertJsonPath('data.0.away_team.tags', []);
    }

    /**
     * The flag is its own decision, not a reading of `is_public`: a card has one
     * line of room, and a published date of birth does not belong on it.
     */
    public function test_the_schedule_flag_is_independent_of_the_public_one(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $event = $this->badmintonEvent($org, [
            'team_fields' => [],
            'player_fields' => [
                [
                    'key' => 'nama_pb',
                    'label' => 'Nama PB',
                    'type' => 'short_text',
                    // Published AND on the card.
                    'is_public' => true,
                    'in_schedule' => true,
                ],
                [
                    'key' => 'tgl_lahir',
                    'label' => 'Tanggal Lahir',
                    'type' => 'date',
                    // Published but NOT on the card.
                    'is_public' => true,
                    'in_schedule' => false,
                ],
                [
                    'key' => 'komunitas',
                    'label' => 'Komunitas',
                    'type' => 'short_text',
                    // On the card but NOT published.
                    'is_public' => false,
                    'in_schedule' => true,
                ],
            ],
            'team_official_fields' => [],
            'team_documents' => [],
            'player_documents' => [],
            'team_official_documents' => [],
        ]);

        $this->fixture(
            $event,
            'Ganda Dewasa Putra',
            [[], [[
                'nama_pb' => 'PB. Uncrown Medan',
                'tgl_lahir' => '2003-09-26',
                'komunitas' => 'Garuda Muda',
            ]]],
            [[], [[]]],
        );

        // Both ticked fields, in schema order; the published-only one is absent.
        $this->actingAs($user, 'api')
            ->getJson($this->scheduleUrl($org, $event, 'Ganda Dewasa Putra'))
            ->assertOk()
            ->assertJsonPath('data.0.home_team.tags', ['PB. Uncrown Medan', 'Garuda Muda']);
    }

    /**
     * An absent flag must read as off. A schema saved before this existed must
     * not start printing answers people gave to a form that promised nothing of
     * the sort.
     */
    public function test_an_untouched_schema_prints_nothing(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        // The same form as production's badminton event, flag absent entirely.
        $form = $this->playerClubForm();
        unset($form['player_fields'][0]['in_schedule']);

        $event = $this->badmintonEvent($org, $form);

        $this->fixture(
            $event,
            'Ganda Dewasa Putra',
            [[], [['nama_pb' => 'PB. Uncrown Medan']]],
            [[], [[]]],
        );

        $this->actingAs($user, 'api')
            ->getJson($this->scheduleUrl($org, $event, 'Ganda Dewasa Putra'))
            ->assertOk()
            ->assertJsonPath('data.0.home_team.tags', [])
            ->assertJsonPath('data.0.away_team.tags', []);
    }

    /**
     * The public schedule prints the same tags as the organizer's, from the same
     * flag — a club name a spectator reads off a card is the one the committee
     * sees. Compared in one test because a card that is right on one screen and
     * blank on the other is the "two readers of one rule" bug.
     */
    public function test_the_public_schedule_prints_the_same_tags(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);
        $event = $this->badmintonEvent($org, $this->playerClubForm());

        $this->fixture(
            $event,
            'Ganda Dewasa Putra',
            [[], [['nama_pb' => 'PB. Uncrown Medan']]],
            [[], [['nama_pb' => 'PB. Trimas']]],
        );

        $category = $event->categories->firstWhere('name', 'Ganda Dewasa Putra');

        $this->getJson("/api/v1/public/events/{$org->slug}/{$event->slug}/categories/{$category->slug}/matches")
            ->assertOk()
            ->assertJsonPath('data.0.home_team.tags', ['PB. Uncrown Medan'])
            ->assertJsonPath('data.0.away_team.tags', ['PB. Trimas']);
    }

    /**
     * The rosters a player tag is read from are eager loaded, and ONLY for an
     * event that asked for them.
     *
     * Compared twice over, because a single count proves neither half: a
     * **fixed** number as the schedule grows is what says "per response, not
     * per row" (158 fixtures pulling two squads each would be 316 extra queries
     * and preventLazyLoading is off, so it would not throw — it would just be
     * slow), and **zero** for the event that ticked nothing is what says the
     * rosters are not loaded for everyone.
     */
    public function test_the_rosters_are_loaded_per_response_and_only_when_a_tag_needs_them(): void
    {
        $user = User::factory()->create();
        $org = $this->org($user);

        $small = $this->badmintonEvent($org, $this->playerClubForm());
        $big = $this->badmintonEvent($org, $this->playerClubForm());
        $plain = $this->badmintonEvent($org, $this->playerClubForm(inSchedule: false));

        foreach ([[$small, 1], [$big, 6], [$plain, 6]] as [$event, $fixtures]) {
            foreach (range(1, $fixtures) as $ignored) {
                $this->fixture(
                    $event,
                    'Ganda Dewasa Putra',
                    [[], [['nama_pb' => 'PB. A']]],
                    [[], [['nama_pb' => 'PB. B']]],
                );
            }
        }

        $count = function (Event $event) use ($user, $org) {
            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->actingAs($user, 'api')
                ->getJson($this->scheduleUrl($org, $event, 'Ganda Dewasa Putra'))
                ->assertOk();

            $log = collect(DB::getRawQueryLog())
                ->filter(fn ($q) => str_contains($q['raw_query'], 'from "players"'))
                ->count();

            DB::disableQueryLog();

            return $log;
        };

        // Six times the fixtures, the same number of roster queries: two (one
        // per side's relation), spent on the response rather than on each row.
        $this->assertSame($count($small), $count($big));
        // And none at all for the event that ticked nothing — six fixtures'
        // worth of squads it has no use for.
        $this->assertSame(0, $count($plain));
    }
}
