<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\GameMatch;
use App\Models\MatchRubber;
use App\Models\Organization;
use App\Models\Team;
use App\Services\MatchResultService;
use App\Services\RubberService;
use App\Services\ScheduleService;
use App\Services\TeamRosterService;
use App\Support\MatchScoring;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A badminton event covering all three entrant shapes at once — tunggal, ganda,
 * and beregu — so the participant-type and partai work can be clicked through
 * end to end in the demo organization.
 *
 * Everything is built through the real services (TeamRosterService derives the
 * entrant names, ScheduleService lays out the fixtures, RubberService seeds the
 * partai, MatchResultService rolls the ties up). A seeder that wrote the rows
 * itself would prove nothing about the code the app actually runs.
 *
 * Idempotent: keyed on the event slug, and a category that already has entrants
 * is left alone. Run it explicitly — like the other demo seeders it is not part
 * of DatabaseSeeder:
 *
 *   php artisan db:seed --class=BadmintonDemoSeeder
 */
class BadmintonDemoSeeder extends Seeder
{
    /** The tie every squad match is played over. */
    private const RUBBER_FORMAT = [
        ['label' => 'Ganda Putra', 'type' => 'double'],
        ['label' => 'Tunggal Putra', 'type' => 'single'],
        ['label' => 'Ganda Campuran', 'type' => 'double'],
    ];

    /** @var list<string> */
    private array $singles = [
        'Dimas Prayoga', 'Ammar Wijaya', 'Ucang Nugroho', 'Devan Saputra',
        'Bagas Hidayat', 'Rizki Ramadhan',
    ];

    /** @var list<array{0: string, 1: string}> */
    private array $pairs = [
        ['Dimas Prayoga', 'Ammar Wijaya'],
        ['Ucang Nugroho', 'Devan Saputra'],
        ['Bagas Hidayat', 'Rizki Ramadhan'],
        ['Galih Permana', 'Naufal Firmansyah'],
    ];

    /**
     * Squads named after countries, the way a beregu draw actually reads. The
     * first two rosters are the worked example from the spec, so the seeded tie
     * below is literally "Spanyol 3-0 Argentina".
     *
     * @var array<string, list<string>>
     */
    private array $squads = [
        'Spanyol' => ['Dimas', 'Ammar', 'Jo', 'Yolan'],
        'Argentina' => ['Ucang', 'Devan', 'Ratih', 'Bagas'],
        'Indonesia' => ['Galih', 'Naufal', 'Sari', 'Putri'],
        'Jepang' => ['Kenta', 'Hiro', 'Aiko', 'Yuki'],
    ];

    public function __construct(
        private readonly TeamRosterService $roster = new TeamRosterService,
    ) {}

    public function run(): void
    {
        $org = Organization::where('slug', 'demo-organizer')->first();

        if (! $org) {
            $this->command?->warn('BadmintonDemoSeeder dilewati: organisasi "demo-organizer" belum ada (jalankan UserSeeder dulu).');

            return;
        }

        $event = $this->makeEvent($org);

        $singles = $this->makeCategory($event, 'Tunggal Putra', 'single', 'league', 50_000, 0);
        $doubles = $this->makeCategory($event, 'Ganda Putra', 'double', 'knockout_single', 75_000, 1);
        $squads = $this->makeCategory($event, 'Beregu Campuran', 'team', 'league', 250_000, 2);

        $this->seedSingles($singles);
        $this->seedDoubles($doubles);
        $this->seedSquads($squads);

        $this->command?->info("Seeded event badminton \"{$event->name}\" untuk \"{$org->name}\".");
        $this->command?->table(
            ['Kategori', 'Jenis peserta', 'Format', 'Peserta'],
            [
                [$singles->name, 'Tunggal', $singles->tournament_format, $singles->teams()->count()],
                [$doubles->name, 'Ganda', $doubles->tournament_format, $doubles->teams()->count()],
                [$squads->name, 'Tim (3 partai)', $squads->tournament_format, $squads->teams()->count()],
            ],
        );
        $this->command?->info('Login: owner@flo-event.id / password');
    }

    private function makeEvent(Organization $org): Event
    {
        return Event::updateOrCreate(
            ['organization_id' => $org->id, 'slug' => 'kejuaraan-badminton-nusantara-2026'],
            [
                'name' => 'Kejuaraan Badminton Nusantara 2026',
                'sport_type' => 'badminton',
                'status' => 'ongoing',
                'start_date' => now()->subDays(2),
                'end_date' => now()->addDays(9),
                'registration_open' => now()->subDays(30),
                'registration_close' => now()->subDays(4),
                'location_name' => 'GOR Bulungan',
                'location_address' => 'Jl. Bulungan, Jakarta Selatan',
                'description' => 'Turnamen badminton dengan tiga nomor sekaligus: tunggal, ganda, dan beregu. '
                    .'Nomor beregu dimainkan atas tiga partai — hasil tim dihitung dari partai yang dimenangkan.',
            ],
        );
    }

    private function makeCategory(
        Event $event,
        string $name,
        string $participantType,
        string $format,
        int $fee,
        int $sort,
    ): EventCategory {
        return EventCategory::updateOrCreate(
            ['event_id' => $event->id, 'slug' => Str::slug($name)],
            [
                'name' => $name,
                'participant_type' => $participantType,
                // Only the squad category plays over partai; the other two are a
                // single run of sets, exactly as usesRubbers() decides.
                'rubber_format' => $participantType === 'team' ? self::RUBBER_FORMAT : null,
                'tournament_format' => $format,
                'registration_fee' => $fee,
                'max_teams' => 16,
                'sort_order' => $sort,
            ],
        );
    }

    // ---- Entrants ----

    private function seedSingles(EventCategory $category): void
    {
        foreach ($this->singles as $name) {
            $this->makeEntrant($category, [$name]);
        }

        $this->playSetBased($category);
    }

    private function seedDoubles(EventCategory $category): void
    {
        foreach ($this->pairs as $pair) {
            $this->makeEntrant($category, $pair);
        }

        $this->playSetBased($category);
    }

    /**
     * Create one entrant with the given players.
     *
     * The name goes in as a placeholder on purpose: TeamRosterService derives
     * the real one from the roster ("Dimas Prayoga / Ammar Wijaya"), and letting
     * it do so here is what keeps the seeded data identical to what the
     * registration form produces.
     *
     * @param  list<string>  $players
     */
    private function makeEntrant(EventCategory $category, array $players): ?Team
    {
        $placeholder = implode(' / ', $players);

        if ($category->teams()->where('name', $placeholder)->exists()) {
            return null;
        }

        $registeredAt = Carbon::parse($category->event->registration_open)->addDays(random_int(1, 5));

        $team = Team::create([
            'event_id' => $category->event_id,
            'category_id' => $category->id,
            'name' => $placeholder,
            'contact_name' => $players[0],
            'contact_phone' => '08'.random_int(11_000_0000, 13_999_9999),
            'status' => 'approved',
            'registered_at' => $registeredAt,
            'approved_at' => $registeredAt->copy()->addDay(),
            'payment_status' => 'paid',
            'payment_amount' => (float) $category->registration_fee,
            'platform_fee' => 0,
            'paid_at' => $registeredAt->copy()->addHours(2),
        ]);

        // The category is needed to size the roster and derive the name, and the
        // event to validate positions — attach both rather than re-querying.
        $team->setRelation('category', $category);
        $team->setRelation('event', $category->event);

        $this->roster->syncPlayers($team, array_map(
            fn (string $name) => ['full_name' => $name, 'position' => count($players) > 1 ? 'doubles' : 'singles'],
            $players,
        ));

        return $team;
    }

    // ---- Squads & their ties ----

    private function seedSquads(EventCategory $category): void
    {
        foreach ($this->squads as $name => $players) {
            if ($category->teams()->where('name', $name)->exists()) {
                continue;
            }

            $registeredAt = Carbon::parse($category->event->registration_open)->addDays(random_int(1, 5));

            $team = Team::create([
                'event_id' => $category->event_id,
                'category_id' => $category->id,
                'name' => $name,
                'contact_name' => $players[0],
                'contact_phone' => '08'.random_int(11_000_0000, 13_999_9999),
                'status' => 'approved',
                'registered_at' => $registeredAt,
                'approved_at' => $registeredAt->copy()->addDay(),
                'payment_status' => 'paid',
                'payment_amount' => (float) $category->registration_fee,
                'platform_fee' => 0,
                'paid_at' => $registeredAt->copy()->addHours(2),
            ]);

            $team->setRelation('category', $category);
            $team->setRelation('event', $category->event);

            // A squad keeps its own name, so the roster is a plain list.
            $this->roster->syncPlayers($team, array_map(
                fn (string $p, int $i) => [
                    'full_name' => $p,
                    'jersey_number' => (string) ($i + 1),
                    'position' => $i < 2 ? 'singles' : 'doubles',
                ],
                $players,
                array_keys($players),
            ));
        }

        if (! $this->generate($category)) {
            return;
        }

        foreach ($category->matches()->orderBy('round')->orderBy('order')->get() as $match) {
            $this->playTie($match);
        }
    }

    /**
     * Play one tie, partai by partai, then let MatchResultService roll it up —
     * the same path the organizer's editor takes.
     */
    private function playTie(GameMatch $match): void
    {
        if ($match->status === 'finished' || ! $match->home_team_id || ! $match->away_team_id) {
            return;
        }

        // Fixtures are born with their partai via GameMatch's created hook, but
        // a seeder may be running WithoutModelEvents — ask for them explicitly.
        app(RubberService::class)->seedFor($match);

        $rubbers = $match->rubbers()->get();

        if ($rubbers->isEmpty()) {
            return;
        }

        $home = $match->homeTeam->players->pluck('id')->all();
        $away = $match->awayTeam->players->pluck('id')->all();

        // The worked example from the spec, seeded verbatim so there is always
        // one tie whose numbers can be checked against it by eye.
        $scripted = $this->scriptedTie($match);

        foreach ($rubbers as $i => $rubber) {
            $slots = $rubber->lineupSize();

            app(RubberService::class)->applySets(
                $this->withLineup($rubber, array_slice($this->rotate($home, $i), 0, $slots), array_slice($this->rotate($away, $i), 0, $slots)),
                $scripted[$i] ?? $this->randomSets(),
            );
        }

        $tally = app(RubberService::class)->tally($match->rubbers()->get());

        app(MatchResultService::class)->apply($match, [
            'status' => 'finished',
            'home_score' => $tally['home'],
            'away_score' => $tally['away'],
            'sets' => null,
        ], confirm: $tally['home'] !== $tally['away']);
    }

    /**
     * Spanyol 3-0 Argentina, exactly as specified:
     *   Ganda Putra    21-16 / 22-20
     *   Tunggal Putra  15-21 / 21-18 / 24-22
     *   Ganda Campuran 22-15 / 23-21
     *
     * @return array<int, array<int, array{home: int, away: int}>>
     */
    private function scriptedTie(GameMatch $match): array
    {
        $pairing = [$match->homeTeam->name, $match->awayTeam->name];

        if (! in_array($pairing, [['Spanyol', 'Argentina'], ['Argentina', 'Spanyol']], true)) {
            return [];
        }

        $script = [
            [['home' => 21, 'away' => 16], ['home' => 22, 'away' => 20]],
            [['home' => 15, 'away' => 21], ['home' => 21, 'away' => 18], ['home' => 24, 'away' => 22]],
            [['home' => 22, 'away' => 15], ['home' => 23, 'away' => 21]],
        ];

        // The draw decides who is seated at home, and it is not always Spanyol.
        // Mirror rather than skip, so the worked example is always present — it
        // then reads "Argentina 0-3 Spanyol", which is the same tie.
        if ($pairing[0] === 'Spanyol') {
            return $script;
        }

        return array_map(
            fn (array $sets) => array_map(
                fn (array $s) => ['home' => $s['away'], 'away' => $s['home']],
                $sets,
            ),
            $script,
        );
    }

    /**
     * @param  list<string>  $home
     * @param  list<string>  $away
     */
    private function withLineup(MatchRubber $rubber, array $home, array $away): MatchRubber
    {
        $rubber->update(['home_player_ids' => $home, 'away_player_ids' => $away]);

        return $rubber;
    }

    /**
     * Shift a roster so consecutive partai field different players — a squad of
     * four playing three partai should not send the same pair out every time.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function rotate(array $ids, int $by): array
    {
        if ($ids === []) {
            return [];
        }

        $by %= count($ids);

        return array_merge(array_slice($ids, $by), array_slice($ids, 0, $by));
    }

    // ---- Shared helpers ----

    /** Lay out the fixtures for a category, or report that there is nothing to lay out. */
    private function generate(EventCategory $category): bool
    {
        if ($category->matches()->exists()) {
            return false;
        }

        if ($category->teams()->where('status', 'approved')->count() < 2) {
            return false;
        }

        $schedule = app(ScheduleService::class);

        $category->engine() === 'knockout_single'
            ? $schedule->generateKnockout($category)
            : $schedule->generateRoundRobin($category);

        $schedule->applySchedule($category);

        return true;
    }

    /** Tunggal & ganda are scored as one run of sets, like any set-based sport. */
    private function playSetBased(EventCategory $category): void
    {
        if (! $this->generate($category)) {
            return;
        }

        $schedule = app(ScheduleService::class);
        $knockout = $category->engine() === 'knockout_single';
        $maxRound = (int) $category->matches()->max('round');

        // Leave the final unplayed in a knockout, so the bracket has a live edge.
        $playUpTo = $knockout ? max(1, $maxRound - 1) : $maxRound;

        for ($r = 1; $r <= $playUpTo; $r++) {
            foreach ($category->matches()->where('round', $r)->orderBy('order')->get() as $match) {
                if ($match->status === 'finished' || ! $match->home_team_id || ! $match->away_team_id) {
                    continue;
                }

                $sets = $this->randomSets();
                $won = MatchScoring::setsWon($sets);

                $match->update([
                    'home_score' => $won['home'],
                    'away_score' => $won['away'],
                    'sets' => $sets,
                    'status' => 'finished',
                    'confirmed_at' => now(),
                ]);

                if ($knockout) {
                    $schedule->advanceWinner($match->fresh());
                }
            }
        }
    }

    /**
     * A best-of-three that always produces a winner — badminton has no draws.
     *
     * @return array<int, array{home: int, away: int}>
     */
    private function randomSets(): array
    {
        $homeWins = (bool) random_int(0, 1);
        $loserSets = random_int(0, 1);

        // 21 to whoever takes the set. Built from "who wins this one" rather than
        // from the set index: indexing it is how the away-wins branch ended up
        // awarding every set to home.
        $set = fn (bool $homeTakes) => $homeTakes
            ? ['home' => 21, 'away' => random_int(12, 19)]
            : ['home' => random_int(12, 19), 'away' => 21];

        $sets = [];

        // The loser's consolation set comes first; the winner closes it out.
        for ($s = 0; $s < $loserSets; $s++) {
            $sets[] = $set(! $homeWins);
        }
        for ($s = 0; $s < 2; $s++) {
            $sets[] = $set($homeWins);
        }

        return $sets;
    }
}
