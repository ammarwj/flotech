<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\Team;
use App\Models\User;
use App\Services\Catalog;
use App\Services\ScheduleService;
use App\Support\MatchScoring;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Populates the demo organization with realistic events, registered clubs, and
 * player rosters so the organizer dashboard and public event pages have data to
 * browse. Idempotent: events are keyed by slug, and an event that already has
 * teams is left untouched.
 */
class DemoEventSeeder extends Seeder
{
    /** @var list<string> */
    private array $firstNames = [
        'Budi', 'Andi', 'Rizki', 'Dwi', 'Eko', 'Agus', 'Fajar', 'Hendra', 'Bayu', 'Yoga',
        'Dimas', 'Arif', 'Galih', 'Reza', 'Wahyu', 'Iqbal', 'Surya', 'Bagus', 'Teguh', 'Aldi',
        'Ferdi', 'Gilang', 'Krisna', 'Naufal', 'Putra',
    ];

    /** @var list<string> */
    private array $lastNames = [
        'Santoso', 'Saputra', 'Pratama', 'Wijaya', 'Nugroho', 'Setiawan', 'Kurniawan',
        'Hidayat', 'Ramadhan', 'Permana', 'Maulana', 'Firmansyah', 'Susanto', 'Gunawan', 'Halim',
    ];

    /** @var list<string> */
    private array $clubNames = [
        'Garuda FC', 'Elang Muda', 'Putra Bangsa', 'Bintang Timur', 'Singa Lapangan',
        'Macan Kemayoran', 'Rajawali United', 'Naga Biru', 'Persatuan Jaya', 'Harimau Sakti',
        'Banteng Merah', 'Gajah Perkasa', 'Camar Putih', 'Kuda Liar', 'Serigala Hitam', 'Phoenix United',
    ];

    public function run(): void
    {
        $org = Organization::where('slug', 'demo-organizer')->first();

        if (! $org) {
            $this->command?->warn('DemoEventSeeder dilewati: organisasi "demo-organizer" belum ada (jalankan UserSeeder dulu).');

            return;
        }

        $participant = User::where('email', 'participant@flo-event.id')->first();

        // Each event runs one-or-more categories. The football event carries two
        // (Senior + U-17) at different formats and fees to exercise the feature.
        // 'play' controls how many rounds get results:
        //   none = fixtures only · early = first rounds · most = all but final · all = every round
        $events = [
            [
                'name' => 'Liga Komunitas Jakarta 2026',
                'sport_type' => 'football',
                'status' => 'ongoing',
                'location_name' => 'GBK Soccer Field',
                'location_address' => 'Jl. Pintu Satu Senayan, Jakarta Pusat',
                'categories' => [
                    ['name' => 'Senior', 'tournament_format' => 'league', 'registration_fee' => 150000, 'max_teams' => 16, 'teams' => 10, 'pending' => 2, 'play' => 'early'],
                    ['name' => 'U-17', 'tournament_format' => 'knockout_single', 'registration_fee' => 100000, 'max_teams' => 8, 'teams' => 6, 'pending' => 1, 'play' => 'none'],
                ],
            ],
            [
                'name' => 'Futsal Championship Cup',
                'sport_type' => 'futsal',
                'status' => 'ongoing',
                'location_name' => 'Sport Center Kuningan',
                'location_address' => 'Jl. HR Rasuna Said, Jakarta Selatan',
                'categories' => [
                    ['name' => 'Umum', 'tournament_format' => 'knockout_single', 'registration_fee' => 100000, 'max_teams' => 8, 'teams' => 8, 'pending' => 0, 'play' => 'most'],
                ],
            ],
            [
                'name' => 'Turnamen Badminton Antar Klub',
                'sport_type' => 'badminton',
                'status' => 'open',
                'location_name' => 'GOR Bulungan',
                'location_address' => 'Jl. Bulungan, Jakarta Selatan',
                'categories' => [
                    ['name' => 'Umum', 'tournament_format' => 'knockout_single', 'registration_fee' => 50000, 'max_teams' => 12, 'teams' => 6, 'pending' => 1, 'play' => 'none'],
                ],
            ],
            [
                'name' => 'Voli Antar Kecamatan Series',
                'sport_type' => 'volleyball',
                'status' => 'finished',
                'location_name' => 'GOR Ciracas',
                'location_address' => 'Jl. Raya Centex, Jakarta Timur',
                'categories' => [
                    ['name' => 'Umum', 'tournament_format' => 'league', 'registration_fee' => 75000, 'max_teams' => 6, 'teams' => 6, 'pending' => 0, 'play' => 'all'],
                ],
            ],
        ];

        $created = 0;

        foreach ($events as $i => $cfg) {
            $event = $this->makeEvent($org, $cfg);

            foreach (array_values($cfg['categories']) as $ci => $catCfg) {
                $category = $this->makeCategory($event, $catCfg, $ci);

                if (! $category->teams()->exists()) {
                    // Distinct club names per category by shuffling the pool.
                    $names = $this->clubNames;
                    shuffle($names);

                    $total = $catCfg['teams'] + $catCfg['pending'];
                    for ($t = 0; $t < $total; $t++) {
                        $isPending = $t >= $catCfg['teams'];
                        $manager = ($i === 0 && $ci === 0 && $t === 0) ? $participant : null;

                        $team = $this->makeTeam($event, $category, $names[$t % count($names)], $catCfg, $isPending, $manager);
                        $this->makePlayers($team, $cfg['sport_type']);
                    }

                    $created++;
                }

                // Fixtures + results (no-op when the category already has matches).
                $this->seedMatches($category, $catCfg);
            }
        }

        $this->command?->info($created > 0
            ? "Seeded {$created} kategori demo beserta klub, pemain, jadwal & hasil untuk \"{$org->name}\"."
            : 'Event demo sudah ada — jadwal/hasil dilengkapi bila belum ada.');
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function makeEvent(Organization $org, array $cfg): Event
    {
        $slug = Str::slug($cfg['name']);

        // Dates relative to "now" so each status looks plausible.
        [$start, $end, $regOpen, $regClose] = match ($cfg['status']) {
            'finished' => [now()->subDays(40), now()->subDays(38), now()->subDays(70), now()->subDays(45)],
            'ongoing' => [now()->subDays(3), now()->addDays(11), now()->subDays(30), now()->subDays(5)],
            default => [now()->addDays(21), now()->addDays(23), now()->subDays(5), now()->addDays(14)],
        };

        return Event::updateOrCreate(
            ['organization_id' => $org->id, 'slug' => $slug],
            [
                'name' => $cfg['name'],
                'sport_type' => $cfg['sport_type'],
                'status' => $cfg['status'],
                'start_date' => $start,
                'end_date' => $end,
                'registration_open' => $regOpen,
                'registration_close' => $regClose,
                'location_name' => $cfg['location_name'],
                'location_address' => $cfg['location_address'],
                'description' => "Turnamen {$cfg['name']} terbuka untuk klub komunitas. "
                    .'Pendaftaran tim, jadwal pertandingan, dan klasemen dikelola lewat platform flo-event.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function makeCategory(Event $event, array $cfg, int $sort): EventCategory
    {
        return EventCategory::updateOrCreate(
            ['event_id' => $event->id, 'slug' => Str::slug($cfg['name'])],
            [
                'name' => $cfg['name'],
                'tournament_format' => $cfg['tournament_format'],
                'registration_fee' => $cfg['registration_fee'],
                'max_teams' => $cfg['max_teams'],
                'sort_order' => $sort,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function makeTeam(Event $event, EventCategory $category, string $name, array $cfg, bool $pending, ?User $manager): Team
    {
        $fee = (float) $cfg['registration_fee'];
        $registeredAt = Carbon::parse($event->registration_open)->addDays(random_int(1, 5));

        return Team::create([
            'event_id' => $event->id,
            'category_id' => $category->id,
            'name' => $name,
            'contact_name' => $this->randomPerson(),
            'contact_phone' => '08'.random_int(11_000_0000, 13_999_9999),
            'status' => $pending ? 'pending' : 'approved',
            'registered_at' => $registeredAt,
            'approved_at' => $pending ? null : $registeredAt->copy()->addDay(),
            'manager_user_id' => $manager?->id,
            'payment_status' => $fee > 0 && $pending ? 'unpaid' : 'paid',
            'payment_amount' => $fee,
            'platform_fee' => 0,
            'paid_at' => $pending ? null : $registeredAt->copy()->addHours(2),
        ]);
    }

    private function makePlayers(Team $team, string $sport): void
    {
        $count = match ($sport) {
            'football' => 16,
            'futsal' => 10,
            'badminton' => 6,
            default => 12,
        };

        // The sport's own positions (SportSeeder) — the demo must not invent a
        // second vocabulary the roster dropdown would then reject.
        $positions = Catalog::positionKeys($sport);

        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'team_id' => $team->id,
                'full_name' => $this->randomPerson(),
                'jersey_number' => (string) $n,
                'position' => $positions === [] ? null : $positions[array_rand($positions)],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        Player::insert($rows);
    }

    /**
     * Generate fixtures via the real ScheduleService, then play a subset of
     * rounds with sport-aware results. No-op if the event already has matches.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function seedMatches(EventCategory $category, array $cfg): void
    {
        if ($category->matches()->exists()) {
            return;
        }
        if ($category->teams()->where('status', 'approved')->count() < 2) {
            return;
        }

        $schedule = app(ScheduleService::class);
        $format = $category->tournament_format;
        $sport = $category->sport_type;
        $isKnockout = str_starts_with($format, 'knockout');

        if (in_array($format, ['league', 'hybrid'], true)) {
            $schedule->generateRoundRobin($category);
        } elseif ($format === 'knockout_single') {
            $schedule->generateKnockout($category);
        } else {
            return; // double-elim demo not needed
        }

        $maxRound = (int) $category->matches()->max('round');
        $playUpTo = match ($cfg['play']) {
            'all' => $maxRound,
            'most' => max(1, $maxRound - 1),
            'early' => $isKnockout ? 1 : min(3, $maxRound),
            default => 0,
        };

        for ($r = 1; $r <= $playUpTo; $r++) {
            // Re-read each round: knockout slots fill in as earlier rounds resolve.
            $matches = $category->matches()->where('round', $r)->orderBy('order')->get();

            foreach ($matches as $m) {
                if ($m->status === 'finished' || ! $m->home_team_id || ! $m->away_team_id) {
                    continue; // byes / not-yet-populated slots
                }

                $this->playMatch($m, $sport, $isKnockout);

                if ($isKnockout) {
                    $schedule->advanceWinner($m->fresh());
                }
            }
        }
    }

    private function playMatch(GameMatch $m, string $sport, bool $decisive): void
    {
        if (MatchScoring::isSetBased($sport)) {
            [$home, $away, $sets] = $this->genSets($sport, (bool) random_int(0, 1));
            $m->update(['home_score' => $home, 'away_score' => $away, 'sets' => $sets, 'status' => 'finished', 'confirmed_at' => now()]);
        } else {
            [$home, $away] = $this->genGoals($decisive);
            $m->update(['home_score' => $home, 'away_score' => $away, 'sets' => null, 'status' => 'finished', 'confirmed_at' => now()]);
        }

        $this->seedPlayerStats($m->fresh(), $sport);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function genGoals(bool $decisive): array
    {
        $home = random_int(0, 4);
        $away = random_int(0, 4);
        if ($decisive && $home === $away) {
            random_int(0, 1) ? $home++ : $away++; // knockouts can't end level
        }

        return [$home, $away];
    }

    /**
     * Best-of-3 (badminton) / best-of-5 (volleyball) with a decisive winner.
     *
     * @return array{0: int, 1: int, 2: array<int, array{home: int, away: int}>}
     */
    private function genSets(string $sport, bool $homeWins): array
    {
        $needed = $sport === 'volleyball' ? 3 : 2;
        $cap = $sport === 'volleyball' ? 25 : 21;
        $loserSets = random_int(0, $needed - 1);

        $homeSets = $homeWins ? $needed : $loserSets;
        $awaySets = $homeWins ? $loserSets : $needed;

        $sets = [];
        for ($s = 0; $s < $homeSets + $awaySets; $s++) {
            $homeWonThis = $s < $homeSets; // winner's sets first
            $loserPts = random_int($cap - 9, $cap - 2);
            $sets[] = $homeWonThis
                ? ['home' => $cap, 'away' => $loserPts]
                : ['home' => $loserPts, 'away' => $cap];
        }

        return [$homeSets, $awaySets, $sets];
    }

    private function seedPlayerStats(GameMatch $m, string $sport): void
    {
        $this->statsForSide($m, (string) $m->home_team_id, (int) $m->home_score, $sport);
        $this->statsForSide($m, (string) $m->away_team_id, (int) $m->away_score, $sport);
    }

    private function statsForSide(GameMatch $m, string $teamId, int $score, string $sport): void
    {
        $players = Player::where('team_id', $teamId)->pluck('id')->all();
        if (empty($players)) {
            return;
        }

        /** @var array<string, array<string, int>> $tally */
        $tally = [];
        $bump = function (string $pid, string $key, int $by = 1) use (&$tally): void {
            $tally[$pid][$key] = ($tally[$pid][$key] ?? 0) + $by;
        };

        if (MatchScoring::isSetBased($sport)) {
            // A few scorers split the rally points, plus aces/blocks.
            $keys = (array) array_rand($players, min(3, count($players)));
            foreach ($keys as $idx) {
                $pid = $players[$idx];
                $bump($pid, 'points', random_int(6, 16));
                $bump($pid, 'aces', random_int(0, 3));
                if ($sport === 'volleyball') {
                    $bump($pid, 'blocks', random_int(0, 3));
                }
            }
        } else {
            // Distribute the goals (and ~60% as assists) across the outfield.
            for ($g = 0; $g < $score; $g++) {
                $bump($players[array_rand($players)], 'goals');
            }
            for ($a = 0; $a < (int) floor($score * 0.6); $a++) {
                $bump($players[array_rand($players)], 'assists');
            }
            if (random_int(0, 2) === 0) {
                $bump($players[array_rand($players)], 'yellow_cards');
            }
        }

        $rows = [];
        foreach ($tally as $pid => $stats) {
            foreach ($stats as $key => $val) {
                if ($val <= 0) {
                    continue;
                }
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'match_id' => $m->id,
                    'team_id' => $teamId,
                    'player_id' => $pid,
                    'stat_key' => $key,
                    'value' => $val,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (! empty($rows)) {
            PlayerMatchStat::insert($rows);
        }
    }

    private function randomPerson(): string
    {
        return $this->firstNames[array_rand($this->firstNames)].' '.$this->lastNames[array_rand($this->lastNames)];
    }
}
