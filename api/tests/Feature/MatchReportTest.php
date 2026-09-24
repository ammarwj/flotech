<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Sport;
use App\Models\SportPosition;
use App\Models\User;
use App\Services\Catalog;
use App\Services\EventPersonnelService;
use App\Services\MatchReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPlannedEvents;
use Tests\TestCase;

/**
 * The post-match report: *"setelah pertandingan selesai itu bisa download
 * laporan pertandingan dalam pdf"*.
 *
 * Every case here is a pair, because every single-sided assertion passes
 * against a route that prints whatever it is handed:
 *
 *  - **Finished prints, unfinished refuses.** Asserting the 200 alone would
 *    stay green with no gate at all.
 *  - **Both doors answer the same.** The staff's route and the organizer's are
 *    one action; asserting only one would pass even if the other had no gate.
 *  - **The HTML is compared, not just produced.** dompdf's bytes are compressed
 *    streams, so `assertStringStartsWith('%PDF')` is true of a page with every
 *    row missing. The layout assertions run against MatchReportService::html().
 */
class MatchReportTest extends TestCase
{
    use CreatesPlannedEvents, RefreshDatabase;

    /**
     * Two approved teams and a fixture between them, registered through the
     * public form so the rosters carry real players.
     *
     * @return array{event: Event, org: Organization, owner: User, category: string, home: array<string, mixed>, away: array<string, mixed>, match: string}
     */
    private function scene(): array
    {
        $owner = User::factory()->create();
        $org = $this->orgFor($owner);
        $event = $this->eventOn($org, attrs: [
            'registration_open' => Carbon::now()->subDay(),
            'registration_close' => Carbon::now()->addDays(10),
            'location_name' => 'Lapangan Stamina',
        ]);

        $category = $event->categories()->create([
            'name' => 'Umum',
            'slug' => 'umum',
            'tournament_format' => 'league',
            'registration_fee' => 0,
            'sort_order' => 0,
        ]);

        $sides = [];

        foreach (['Home', 'Away'] as $name) {
            $manager = User::factory()->create();

            $team = $this->actingAs($manager, 'api')
                ->postJson("/api/v1/public/events/{$org->slug}/{$event->slug}/register", [
                    'category_id' => $category->id,
                    'name' => $name.' FC',
                    'contact_name' => 'Andi',
                    'contact_phone' => '08123456789',
                    'players' => [
                        ['full_name' => $name.' Striker', 'jersey_number' => '9', 'position' => 'forward'],
                        ['full_name' => $name.' Keeper', 'jersey_number' => '1', 'position' => 'goalkeeper'],
                    ],
                    'officials' => [
                        ['full_name' => $name.' Coach', 'role' => 'head_coach'],
                    ],
                ])
                ->assertCreated()
                ->json('data.team');

            $this->actingAs($owner, 'api')
                ->patchJson("/api/v1/organizations/{$org->id}/events/{$event->id}/registrations/{$team['id']}", [
                    'status' => 'approved',
                ])
                ->assertOk();

            $sides[$name] = ['manager' => $manager, 'team' => $team];
        }

        $match = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/organizations/{$org->id}/events/{$event->id}/categories/{$category->id}/matches", [
                'home_team_id' => $sides['Home']['team']['id'],
                'away_team_id' => $sides['Away']['team']['id'],
                'scheduled_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->json('data.id');

        return [
            'event' => $event,
            'org' => $org,
            'owner' => $owner,
            'category' => $category->id,
            'home' => $sides['Home'],
            'away' => $sides['Away'],
            'match' => $match,
        ];
    }

    /** Finish the fixture with a scoreline. */
    private function finish(array $scene, int $home = 2, int $away = 1): void
    {
        $this->actingAs($scene['owner'], 'api')
            ->patchJson("/api/v1/organizations/{$scene['org']->id}/matches/{$scene['match']}", [
                'status' => 'finished',
                'home_score' => $home,
                'away_score' => $away,
            ])
            ->assertOk();
    }

    /**
     * The event's crew, rotation flag cleared — every officiating route sits
     * behind `password.rotated`, and the invite's default password is not what
     * this file is testing.
     *
     * @param  array<string, string>  $byKind  kind => email
     * @return array<string, User>
     */
    private function crew(Event $event, array $byKind): array
    {
        app(EventPersonnelService::class)->sync($event, array_map(
            fn (string $kind, string $email) => ['full_name' => 'Petugas', 'kind' => $kind, 'email' => $email],
            array_keys($byKind),
            array_values($byKind),
        ));

        $users = [];

        foreach ($byKind as $kind => $email) {
            $user = User::where('email', $email)->firstOrFail();
            $user->forceFill(['must_change_password' => false])->save();
            $users[$kind] = $user->fresh();
        }

        return $users;
    }

    public function test_the_report_prints_only_once_the_match_is_finished(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $url = "/api/v1/organizations/{$scene['org']->id}/matches/{$scene['match']}/report";

        // Scheduled: there is no result to report.
        $this->actingAs($scene['owner'], 'api')->get($url)->assertStatus(422);

        $this->actingAs($scene['owner'], 'api')
            ->patchJson("/api/v1/organizations/{$scene['org']->id}/matches/{$scene['match']}/status", [
                'status' => 'ongoing',
            ])
            ->assertOk();

        // Kicked off is not finished — the same request, one state short.
        $this->actingAs($scene['owner'], 'api')->get($url)->assertStatus(422);

        $this->finish($scene);

        $response = $this->actingAs($scene['owner'], 'api')->get($url)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_staff_door_answers_exactly_like_the_organizer_door(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $crew = $this->crew($scene['event'], [
            'staff' => 'staf@example.test',
            'referee' => 'wasit@example.test',
        ]);

        $staffUrl = "/api/v1/officiating/events/{$scene['event']->id}/matches/{$scene['match']}/report";

        // Unfinished refuses on this door too — a second copy of the gate is
        // exactly what having one controller is meant to prevent.
        $this->actingAs($crew['staff'], 'api')->get($staffUrl)->assertStatus(422);

        $this->finish($scene);

        $this->assertStringStartsWith(
            '%PDF',
            $this->actingAs($crew['staff'], 'api')->get($staffUrl)->assertOk()->getContent(),
        );

        // And the role split still refuses somebody: printing is the staff's.
        $this->actingAs($crew['referee'], 'api')->get($staffUrl)->assertStatus(403);
    }

    public function test_a_match_from_another_event_is_not_reachable(): void
    {
        Notification::fake();

        $mine = $this->scene();
        $theirs = $this->scene();

        $this->finish($theirs);

        $staff = $this->crew($mine['event'], ['staff' => 'staf@example.test'])['staff'];

        $this->actingAs($staff, 'api')
            ->get("/api/v1/officiating/events/{$mine['event']->id}/matches/{$theirs['match']}/report")
            ->assertStatus(404);

        // And the organizer's door is scoped the same way.
        $this->actingAs($mine['owner'], 'api')
            ->get("/api/v1/organizations/{$mine['org']->id}/matches/{$theirs['match']}/report")
            ->assertStatus(404);
    }

    public function test_the_sheet_carries_the_squads_the_scoreline_and_the_recorded_stats(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $this->finish($scene, home: 2, away: 1);

        $striker = collect($scene['home']['team']['players'])->firstWhere('jersey_number', '9');

        $this->actingAs($scene['owner'], 'api')
            ->putJson("/api/v1/organizations/{$scene['org']->id}/matches/{$scene['match']}/stats", [
                'stats' => [
                    ['player_id' => $striker['id'], 'stat_key' => 'goals', 'value' => 2],
                    ['player_id' => $striker['id'], 'stat_key' => 'yellow_cards', 'value' => 1],
                ],
            ])
            ->assertOk();

        $match = GameMatch::findOrFail($scene['match']);
        $html = app(MatchReportService::class)->html($match);

        $this->assertStringContainsString('Home FC', $html);
        $this->assertStringContainsString('Away FC', $html);
        $this->assertStringContainsString('Home Striker', $html);
        $this->assertStringContainsString('Away Keeper', $html);
        // Official roles print as their catalog labels, not keys. Positions
        // print as codes now, so their labels live in the legend — asserted in
        // its own test below, where the code and its expansion are compared.
        $this->assertStringContainsString('Pelatih Kepala', $html);
        $this->assertStringNotContainsString('head_coach', $html);
        $this->assertStringNotContainsString('forward', $html);
        // The venue falls back to the event's when the fixture has none.
        $this->assertStringContainsString('Lapangan Stamina', $html);
        // The blank cells the schema cannot answer are printed, not dropped:
        // that is the whole reason this sheet is worth handing to a panitia.
        foreach (['NP', 'Cuaca', 'Temperatur', 'Penonton', 'Warna kostum', 'Catatan wasit'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }

        // The recorded stat reaches the squad table. Asserting the number alone
        // would pass on the scoreline's own "2", so it is the row that is read.
        $row = $this->rowFor($html, 'Home Striker');
        $this->assertStringContainsString('>2<', $row);
        $this->assertStringContainsString('>1<', $row);

        // The keeper played the same match and recorded nothing — their cells
        // stay empty. Without this the assertion above passes on a template
        // that stamps every cell with the same number.
        $this->assertStringNotContainsString('>2<', $this->rowFor($html, 'Home Keeper'));
    }

    public function test_a_player_with_stats_but_no_place_on_the_sheet_still_appears(): void
    {
        Notification::fake();

        $scene = $this->scene();

        $keeper = collect($scene['home']['team']['players'])->firstWhere('jersey_number', '1');
        $striker = collect($scene['home']['team']['players'])->firstWhere('jersey_number', '9');

        // A team sheet naming only the striker.
        $this->actingAs($scene['home']['manager'], 'api')
            ->putJson("/api/v1/my-teams/{$scene['home']['team']['id']}/matches/{$scene['match']}/lineup", [
                'players' => [['player_id' => $striker['id'], 'role' => 'starter']],
                'officials' => [],
            ])
            ->assertOk();

        $this->finish($scene, home: 1, away: 0);

        $match = GameMatch::findOrFail($scene['match']);

        // Before: the keeper is not named and has no stats, so the sheet is the
        // whole squad list and they are absent.
        $this->assertStringNotContainsString('Home Keeper', app(MatchReportService::class)->html($match));

        $this->actingAs($scene['owner'], 'api')
            ->putJson("/api/v1/organizations/{$scene['org']->id}/matches/{$scene['match']}/stats", [
                'stats' => [['player_id' => $keeper['id'], 'stat_key' => 'yellow_cards', 'value' => 1]],
            ])
            ->assertOk();

        // After: a card against a player nobody named would otherwise be on the
        // scoreline and nowhere on the report of the match that produced it.
        $after = app(MatchReportService::class)->html($match->fresh());

        $this->assertStringContainsString('Home Keeper', $after);
        $this->assertStringContainsString('Lainnya', $after);
    }

    public function test_the_penalty_row_is_filled_only_by_a_shootout_and_extra_time_never_is(): void
    {
        Notification::fake();

        $scene = $this->scene();

        // A second fixture in the same category, marked as a decider — that is
        // what makes a level scoreline owe a shootout (MatchResultService::
        // mustProduceWinner()).
        $decider = $this->actingAs($scene['owner'], 'api')
            ->postJson("/api/v1/organizations/{$scene['org']->id}/events/{$scene['event']->id}/categories/{$scene['category']}/matches", [
                'home_team_id' => $scene['home']['team']['id'],
                'away_team_id' => $scene['away']['team']['id'],
                'scheduled_at' => Carbon::now()->addDays(2)->toIso8601String(),
                'stage' => 'playoff',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($scene['owner'], 'api')
            ->patchJson("/api/v1/organizations/{$scene['org']->id}/matches/{$decider}", [
                'status' => 'finished',
                'home_score' => 1,
                'away_score' => 1,
                'home_penalty' => 4,
                'away_penalty' => 3,
            ])
            ->assertOk();

        $this->finish($scene, home: 2, away: 1);

        $service = app(MatchReportService::class);
        $plain = $service->html(GameMatch::findOrFail($scene['match']));
        $shootout = $service->html(GameMatch::findOrFail($decider));

        // Both sheets carry every row of the period table — a goal-based sport
        // stores no half-time and no extra-time score, so those print empty and
        // ruled for the panitia, which is what the paper form has too.
        foreach ([$plain, $shootout] as $html) {
            foreach (['Babak 1', 'Babak 2', 'Extra Time', 'Penalty'] as $label) {
                $this->assertStringContainsString($label, $html);
            }
        }

        // The rows are always there; what differs is whether they are filled.
        // Asserting 4-3 on the shootout alone would stay green on a template
        // that filled every row from the same place, so the pair is compared:
        // Penalty carries the stored numbers, Extra Time stays a blank box on
        // the very same sheet.
        $penalty = $this->rowFor($shootout, 'Penalty');
        $this->assertStringContainsString('>4<', $penalty);
        $this->assertStringContainsString('>3<', $penalty);
        $this->assertStringNotContainsString('>4<', $this->rowFor($shootout, 'Extra Time'));

        // And a match that never went to penalties prints the row empty rather
        // than inventing a number for it.
        $this->assertStringNotContainsString('>4<', $this->rowFor($plain, 'Penalty'));
        $this->assertStringNotContainsString('>3<', $this->rowFor($plain, 'Penalty'));
    }

    public function test_positions_print_as_codes_with_a_legend_and_fall_back_to_labels_when_the_codes_collide(): void
    {
        Notification::fake();

        $scene = $this->scene();
        $this->finish($scene);

        $match = GameMatch::findOrFail($scene['match']);
        $service = app(MatchReportService::class);

        // Football's labels give distinct initials, so the POS column is a code
        // and the legend under the squads expands it.
        $coded = $service->html($match);

        $this->assertStringContainsString('>P<', $this->rowFor($coded, 'Home Striker'));
        $this->assertStringContainsString('P = Penyerang', $coded);
        $this->assertStringContainsString('K = Kiper', $coded);
        // Only the positions somebody actually plays. Bek Sayap is in the
        // catalogue and in nobody's squad, so it has no business on the page.
        $this->assertStringNotContainsString('Bek Sayap', $coded);

        // Rename one label so two positions would derive the same letter. The
        // map is abandoned wholesale rather than merging them: a POS column
        // where K means two different things is worse than a wide one, because
        // the reader cannot tell it happened.
        $sport = Sport::where('slug', $scene['event']->sport_type)->firstOrFail();
        SportPosition::where('sport_id', $sport->id)
            ->where('position_key', 'forward')
            ->update(['label' => 'Kedua']);
        Catalog::flush();

        $collided = $service->html($match->fresh());

        $this->assertStringContainsString('Kedua', $this->rowFor($collided, 'Home Striker'));
        $this->assertStringContainsString('Kiper', $this->rowFor($collided, 'Home Keeper'));
        // No legend either — the rows carry full labels, so there is nothing
        // left to expand.
        $this->assertStringNotContainsString('K = ', $collided);
    }

    /** The table row a name sits in — everything between its `<tr>` and `</tr>`. */
    private function rowFor(string $html, string $name): string
    {
        $at = strpos($html, $name);
        $this->assertNotFalse($at, "Nama {$name} tidak ada di lembar.");

        $open = strrpos(substr($html, 0, $at), '<tr');
        $close = strpos($html, '</tr>', $at);

        return substr($html, $open, $close - $open);
    }
}
