<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Organization;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\Team;
use App\Models\User;
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

    /** @var list<string> */
    private array $cities = [
        'Jakarta', 'Bandung', 'Surabaya', 'Semarang', 'Yogyakarta',
        'Bekasi', 'Depok', 'Tangerang', 'Bogor', 'Malang',
    ];

    /** @var list<string> */
    private array $colors = ['Merah', 'Biru', 'Putih', 'Hijau', 'Kuning', 'Hitam', 'Oranye'];

    public function run(): void
    {
        $org = Organization::where('slug', 'demo-organizer')->first();

        if (! $org) {
            $this->command?->warn('DemoEventSeeder dilewati: organisasi "demo-organizer" belum ada (jalankan UserSeeder dulu).');

            return;
        }

        $participant = User::where('email', 'participant@flo-event.id')->first();

        // 'play' controls how many rounds get results:
        //   none = fixtures only · early = first rounds · most = all but final · all = every round
        $events = [
            [
                'name' => 'Liga Komunitas Jakarta 2026',
                'sport_type' => 'football',
                'tournament_format' => 'league',
                'status' => 'ongoing',
                'location_name' => 'GBK Soccer Field',
                'location_address' => 'Jl. Pintu Satu Senayan, Jakarta Pusat',
                'registration_fee' => 150000,
                'max_teams' => 16,
                'teams' => 10,
                'pending' => 2,
                'play' => 'early',
            ],
            [
                'name' => 'Futsal Championship Cup',
                'sport_type' => 'futsal',
                'tournament_format' => 'knockout_single',
                'status' => 'ongoing',
                'location_name' => 'Sport Center Kuningan',
                'location_address' => 'Jl. HR Rasuna Said, Jakarta Selatan',
                'registration_fee' => 100000,
                'max_teams' => 8,
                'teams' => 8,
                'pending' => 0,
                'play' => 'most',
            ],
            [
                'name' => 'Turnamen Badminton Antar Klub',
                'sport_type' => 'badminton',
                'tournament_format' => 'knockout_single',
                'status' => 'open',
                'location_name' => 'GOR Bulungan',
                'location_address' => 'Jl. Bulungan, Jakarta Selatan',
                'registration_fee' => 50000,
                'max_teams' => 12,
                'teams' => 6,
                'pending' => 1,
                'play' => 'none',
            ],
            [
                'name' => 'Voli Antar Kecamatan Series',
                'sport_type' => 'volleyball',
                'tournament_format' => 'league',
                'status' => 'finished',
                'location_name' => 'GOR Ciracas',
                'location_address' => 'Jl. Raya Centex, Jakarta Timur',
                'registration_fee' => 75000,
                'max_teams' => 6,
                'teams' => 6,
                'pending' => 0,
                'play' => 'all',
            ],
        ];

        $created = 0;

        foreach ($events as $i => $cfg) {
            $event = $this->makeEvent($org, $cfg);

            if (! $event->teams()->exists()) {
                // Distinct club names per event by shuffling the pool.
                $names = $this->clubNames;
                shuffle($names);

                $total = $cfg['teams'] + $cfg['pending'];
                for ($t = 0; $t < $total; $t++) {
                    $isPending = $t >= $cfg['teams'];
                    $manager = ($i === 0 && $t === 0) ? $participant : null;

                    $team = $this->makeTeam($event, $names[$t % count($names)], $cfg, $isPending, $manager);
                    $this->makePlayers($team, $cfg['sport_type']);
                }

                $created++;
            }

            // Fixtures + results (no-op when the event already has matches).
            $this->seedMatches($event, $cfg);
        }

        $this->command?->info($created > 0
            ? "Seeded {$created} event demo beserta klub, pemain, jadwal & hasil untuk \"{$org->name}\"."
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
                'tournament_format' => $cfg['tournament_format'],
                'status' => $cfg['status'],
                'start_date' => $start,
                'end_date' => $end,
                'registration_open' => $regOpen,
                'registration_close' => $regClose,
                'location_name' => $cfg['location_name'],
                'location_address' => $cfg['location_address'],
                'description' => "Turnamen {$cfg['name']} terbuka untuk klub komunitas. "
                    .'Pendaftaran tim, jadwal pertandingan, dan klasemen dikelola lewat platform flo-event.',
                'max_teams' => $cfg['max_teams'],
                'registration_fee' => $cfg['registration_fee'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function makeTeam(Event $event, string $name, array $cfg, bool $pending, ?User $manager): Team
    {
        $fee = (float) $cfg['registration_fee'];
        $registeredAt = Carbon::parse($event->registration_open)->addDays(random_int(1, 5));

        return Team::create([
            'event_id' => $event->id,
            'name' => $name,
            'city' => $this->cities[array_rand($this->cities)],
            'jersey_color' => $this->colors[array_rand($this->colors)],
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
        [$count, $positions] = match ($sport) {
            'football' => [16, ['GK', 'Bek', 'Gelandang', 'Sayap', 'Striker']],
            'futsal' => [10, ['Kiper', 'Anchor', 'Flank', 'Pivot']],
            'badminton' => [6, ['Tunggal', 'Ganda']],
            'volleyball' => [12, ['Setter', 'Spiker', 'Blocker', 'Libero']],
            default => [12, ['Pemain']],
        };

        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'team_id' => $team->id,
                'full_name' => $this->randomPerson(),
                'jersey_number' => (string) $n,
                'position' => $positions[array_rand($positions)],
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
    private function seedMatches(Event $event, array $cfg): void
    {
        if ($event->matches()->exists()) {
            return;
        }
        if ($event->teams()->where('status', 'approved')->count() < 2) {
            return;
        }

        $schedule = app(ScheduleService::class);
        $format = $event->tournament_format;
        $isKnockout = str_starts_with($format, 'knockout');

        if (in_array($format, ['league', 'hybrid'], true)) {
            $schedule->generateRoundRobin($event);
        } elseif ($format === 'knockout_single') {
            $schedule->generateKnockout($event);
        } else {
            return; // double-elim demo not needed
        }

        $maxRound = (int) $event->matches()->max('round');
        $playUpTo = match ($cfg['play']) {
            'all' => $maxRound,
            'most' => max(1, $maxRound - 1),
            'early' => $isKnockout ? 1 : min(3, $maxRound),
            default => 0,
        };

        for ($r = 1; $r <= $playUpTo; $r++) {
            // Re-read each round: knockout slots fill in as earlier rounds resolve.
            $matches = $event->matches()->where('round', $r)->orderBy('order')->get();

            foreach ($matches as $m) {
                if ($m->status === 'finished' || ! $m->home_team_id || ! $m->away_team_id) {
                    continue; // byes / not-yet-populated slots
                }

                $this->playMatch($m, $cfg['sport_type'], $isKnockout);

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
