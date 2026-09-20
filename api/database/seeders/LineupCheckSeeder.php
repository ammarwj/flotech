<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventPersonnel;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\Catalog;
use App\Services\LineupService;
use Illuminate\Database\Seeder;

/**
 * TEMPORARY manual-QA seeder for the "susunan pemain" (lineup) feature.
 *
 * Not wired into DatabaseSeeder — run it on demand and drop it once the
 * feature is checked:
 *
 *   php artisan db:seed --class=LineupCheckSeeder
 *
 * Builds, idempotently (keyed by event slug):
 *   - One football event + league category under a fresh "QA Lineup" org.
 *   - Two approved teams with real manager accounts (password: "password"),
 *     each with a full roster.
 *   - One FINISHED + confirmed_at fixture ("sudah di-acc wasit") between them
 *     with a couple of cards on it, so the discipline gate has something to
 *     read on the next fixture.
 *   - One UPCOMING scheduled fixture ("jadwal"), with BOTH teams' lineups
 *     already synced, submitted and approved by a seeded referee — the state
 *     asked for is "susunan pemain sudah disetujui wasit", not a draft.
 */
class LineupCheckSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::updateOrCreate(
            ['email' => 'qa-lineup-owner@floevent.id'],
            ['full_name' => 'QA Lineup Owner', 'role' => 'user', 'password' => 'password', 'is_verified' => true, 'email_verified_at' => now()],
        );

        $org = Organization::firstOrCreate(
            ['slug' => 'qa-lineup'],
            ['name' => 'QA Lineup', 'owner_id' => $owner->id, 'contact_email' => $owner->email],
        );

        OrganizationMember::firstOrCreate(
            ['organization_id' => $org->id, 'user_id' => $owner->id],
            ['role' => 'admin', 'invited_by' => $owner->id],
        );

        $event = Event::updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'qa-lineup-cup'],
            [
                'name' => 'QA Lineup Cup',
                'sport_type' => 'football',
                'status' => 'ongoing',
                'start_date' => now()->subDays(3),
                'end_date' => now()->addDays(11),
                'registration_open' => now()->subDays(20),
                'registration_close' => now()->subDays(5),
                'location_name' => 'Lapangan QA',
                'location_address' => 'Jl. Percobaan No. 1',
            ],
        );

        $category = EventCategory::updateOrCreate(
            ['event_id' => $event->id, 'slug' => 'umum'],
            ['name' => 'Umum', 'tournament_format' => 'league', 'registration_fee' => 0, 'sort_order' => 0],
        );

        if ($category->matches()->exists()) {
            $this->command?->info('QA Lineup sudah ada — dilewati. Hapus event "qa-lineup-cup" untuk membuat ulang.');

            return;
        }

        $home = $this->team($event, $category, 'Garuda QA FC', 'qa-lineup-home@floevent.id');
        $away = $this->team($event, $category, 'Rajawali QA FC', 'qa-lineup-away@floevent.id');

        // Already confirmed by the organizer, with a couple of cards on it so
        // the lineup editor's discipline panel has something to show on the
        // next fixture below.
        $played = GameMatch::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'round' => 1,
            'order' => 1,
            'home_team_id' => $home['team']->id,
            'away_team_id' => $away['team']->id,
            'scheduled_at' => now()->subDays(2),
            'status' => 'finished',
            'home_score' => 2,
            'away_score' => 1,
            'confirmed_at' => now()->subDays(2)->addHours(2),
        ]);

        $this->cardStat($played, $home['players'][0]->id, $home['team']->id, 'yellow_cards', 1);
        $this->cardStat($played, $away['players'][0]->id, $away['team']->id, 'yellow_cards', 1);

        // The fixture to actually open the lineup editor against.
        $next = GameMatch::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'round' => 2,
            'order' => 1,
            'home_team_id' => $home['team']->id,
            'away_team_id' => $away['team']->id,
            'scheduled_at' => now()->addDays(5),
            'status' => 'scheduled',
        ]);

        $referee = User::updateOrCreate(
            ['email' => 'qa-lineup-referee@floevent.id'],
            ['full_name' => 'QA Lineup Referee', 'role' => 'user', 'password' => 'password', 'is_verified' => true, 'email_verified_at' => now()],
        );

        $personnel = EventPersonnel::firstOrCreate(
            ['event_id' => $event->id, 'user_id' => $referee->id],
            ['full_name' => $referee->full_name, 'email' => $referee->email, 'kind' => 'referee'],
        );

        // Real service calls, not hand-written rows: sync()/submit()/approve()
        // are exactly what the manager's editor and the referee's screen call,
        // so a sheet built this way carries the same invariants (derived
        // sort_order, discipline check, submitted_at/reviewed_at, etc.).
        $this->approveLineup($next, $home['team'], $home['players'], $home['manager'], $personnel);
        $this->approveLineup($next, $away['team'], $away['players'], $away['manager'], $personnel);

        $this->command?->info('Seeded QA Lineup Cup (org "qa-lineup"). Login (password: "password"):');
        $this->command?->table(
            ['Email', 'Peran'],
            [
                [$owner->email, 'Admin organisasi'],
                ['qa-lineup-home@floevent.id', 'Manager tim Garuda QA FC'],
                ['qa-lineup-away@floevent.id', 'Manager tim Rajawali QA FC'],
                ['qa-lineup-referee@floevent.id', 'Wasit QA Lineup Cup'],
            ],
        );
    }

    /**
     * @param  list<Player>  $players
     */
    private function approveLineup(GameMatch $match, Team $team, array $players, User $manager, EventPersonnel $referee): void
    {
        $service = app(LineupService::class);

        $starters = array_slice($players, 0, 11);
        $bench = array_slice($players, 11);

        $payload = [
            ...array_map(fn (Player $p) => ['player_id' => $p->id, 'role' => 'starter'], $starters),
            ...array_map(fn (Player $p) => ['player_id' => $p->id, 'role' => 'substitute'], $bench),
        ];

        $lineup = $service->sync($match, $team, $payload, []);
        $service->submit($lineup, $manager);
        $service->approve($lineup, $referee);
    }

    /**
     * @return array{team: Team, players: list<Player>, manager: User}
     */
    private function team(Event $event, EventCategory $category, string $name, string $managerEmail): array
    {
        $manager = User::updateOrCreate(
            ['email' => $managerEmail],
            ['full_name' => $name . ' Manager', 'role' => 'user', 'password' => 'password', 'is_verified' => true, 'email_verified_at' => now()],
        );

        $team = Team::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'name' => $name,
            'contact_name' => $name . ' Manager',
            'contact_phone' => '0812' . random_int(1000_0000, 9999_9999),
            'status' => 'approved',
            'registered_at' => now()->subDays(10),
            'approved_at' => now()->subDays(9),
            'manager_user_id' => $manager->id,
            'payment_status' => 'paid',
            'payment_amount' => 0,
            'platform_fee' => 0,
            'paid_at' => now()->subDays(9),
        ]);

        $positions = Catalog::positionKeys('football');
        $players = [];
        for ($n = 1; $n <= 14; $n++) {
            $players[] = Player::create([
                'team_id' => $team->id,
                'full_name' => "{$name} Pemain {$n}",
                'jersey_number' => (string) $n,
                'position' => $positions === [] ? null : $positions[array_rand($positions)],
                'is_active' => true,
            ]);
        }

        return ['team' => $team, 'players' => $players, 'manager' => $manager];
    }

    private function cardStat(GameMatch $match, string $playerId, string $teamId, string $statKey, int $value): void
    {
        $match->stats()->create([
            'team_id' => $teamId,
            'player_id' => $playerId,
            'stat_key' => $statKey,
            'value' => $value,
        ]);
    }
}
